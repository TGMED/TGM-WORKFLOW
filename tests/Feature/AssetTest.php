<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\Permission;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The asset register, and staff seeing what they hold.
 */
class AssetTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();
    }

    private function staff(): User
    {
        return User::factory()->create(['location_id' => $this->location->id]);
    }

    private function keeper(): User
    {
        $role = Role::query()->create(['slug' => 'stores', 'name' => 'Stores', 'is_system' => false]);
        $role->syncPermissions([Permission::ManageAssets]);

        return User::factory()->roles($role->slug)->create(['location_id' => $this->location->id]);
    }

    public function test_an_asset_is_added_with_its_site_and_spot(): void
    {
        $category = AssetCategory::factory()->create(['name' => 'Laptops']);

        $this->actingAs($this->keeper())
            ->post('/admin/assets', [
                'tag' => 'TGM-00017',
                'name' => 'ThinkPad X1',
                'asset_category_id' => $category->id,
                'location_id' => $this->location->id,
                'spot' => 'Finance office, desk 4',
                'status' => 'available',
                'condition' => 'new',
                'purchase_cost' => 1450000,
            ])
            ->assertSessionHasNoErrors();

        $asset = Asset::query()->sole();
        $this->assertSame($this->location->id, $asset->location_id);
        $this->assertSame('Finance office, desk 4', $asset->spot);
    }

    public function test_assigned_cannot_be_picked_on_the_form(): void
    {
        $this->actingAs($this->keeper())
            ->post('/admin/assets', [
                'tag' => 'TGM-1',
                'name' => 'Desk',
                'asset_category_id' => AssetCategory::factory()->create()->id,
                'status' => 'assigned',
                'condition' => 'good',
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_a_new_asset_can_go_straight_to_somebody(): void
    {
        $keeper = $this->keeper();
        $holder = $this->staff();

        $this->actingAs($keeper)
            ->post('/admin/assets', [
                'tag' => 'TGM-00018',
                'name' => 'ThinkPad X1',
                'asset_category_id' => AssetCategory::factory()->create()->id,
                'status' => 'available',
                'condition' => 'new',
                'assigned_user_id' => $holder->id,
            ])
            ->assertSessionHasNoErrors();

        $asset = Asset::query()->sole();
        $this->assertSame($holder->id, $asset->assigned_user_id);
        $this->assertSame(AssetStatus::Assigned, $asset->status);

        $spell = AssetAssignment::query()->sole();
        $this->assertSame($holder->id, $spell->user_id);
        $this->assertSame($keeper->id, $spell->assigned_by_id);
    }

    public function test_only_an_available_new_asset_can_go_to_somebody(): void
    {
        $this->actingAs($this->keeper())
            ->post('/admin/assets', [
                'tag' => 'TGM-00019',
                'name' => 'Broken projector',
                'asset_category_id' => AssetCategory::factory()->create()->id,
                'status' => 'in_repair',
                'condition' => 'poor',
                'assigned_user_id' => $this->staff()->id,
            ])
            ->assertSessionHasErrors('assigned_user_id');

        $this->assertSame(0, Asset::query()->count());
    }

    public function test_an_asset_needs_a_category(): void
    {
        $this->actingAs($this->keeper())
            ->post('/admin/assets', [
                'tag' => 'TGM-00020',
                'name' => 'Desk',
                'status' => 'available',
                'condition' => 'good',
            ])
            ->assertSessionHasErrors(['asset_category_id' => 'Pick a category. Add one under Categories if the list is empty.']);
    }

    public function test_handing_over_keeps_the_history(): void
    {
        $keeper = $this->keeper();
        $first = $this->staff();
        $second = $this->staff();
        $asset = Asset::factory()->create();

        $this->actingAs($keeper)->post("/admin/assets/{$asset->id}/assign", ['user_id' => $first->id])->assertSessionHasNoErrors();
        $this->actingAs($keeper)->post("/admin/assets/{$asset->id}/assign", ['user_id' => $second->id])->assertSessionHasNoErrors();

        $asset->refresh();
        $this->assertSame($second->id, $asset->assigned_user_id);
        $this->assertSame(AssetStatus::Assigned, $asset->status);

        $spells = AssetAssignment::query()->orderBy('id')->get();
        $this->assertCount(2, $spells);
        $this->assertNotNull($spells[0]->returned_at);
        $this->assertNull($spells[1]->returned_at);

        $this->actingAs($keeper)->post("/admin/assets/{$asset->id}/return")->assertRedirect();

        $this->assertNull($asset->refresh()->assigned_user_id);
        $this->assertSame(AssetStatus::Available, $asset->status);
        $this->assertSame(0, AssetAssignment::query()->whereNull('returned_at')->count());
    }

    public function test_sending_an_assigned_asset_for_repair_takes_it_back(): void
    {
        $keeper = $this->keeper();
        $asset = Asset::factory()->create();
        $this->actingAs($keeper)->post("/admin/assets/{$asset->id}/assign", ['user_id' => $this->staff()->id]);

        $this->actingAs($keeper)
            ->put("/admin/assets/{$asset->id}", [
                'tag' => $asset->tag,
                'name' => $asset->name,
                'asset_category_id' => $asset->asset_category_id,
                'status' => 'in_repair',
                'condition' => 'poor',
            ])
            ->assertSessionHasNoErrors();

        $asset->refresh();
        $this->assertSame(AssetStatus::InRepair, $asset->status);
        $this->assertNull($asset->assigned_user_id);
    }

    public function test_staff_see_only_what_is_assigned_to_them(): void
    {
        $me = $this->staff();
        $mine = Asset::factory()->create(['assigned_user_id' => $me->id, 'status' => AssetStatus::Assigned]);
        Asset::factory()->create(['assigned_user_id' => $this->staff()->id, 'status' => AssetStatus::Assigned]);
        Asset::factory()->create();

        $this->actingAs($me)
            ->get('/assets')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assets')
                ->has('assets', 1)
                ->where('assets.0.tag', $mine->tag));
    }

    public function test_the_register_needs_its_own_permission(): void
    {
        $this->actingAs($this->staff())->get('/admin/assets')->assertForbidden();
    }

    public function test_a_category_with_assets_is_not_removed(): void
    {
        $asset = Asset::factory()->create();

        $this->actingAs($this->keeper())->delete("/admin/asset-categories/{$asset->asset_category_id}");

        $this->assertNotNull(AssetCategory::query()->find($asset->asset_category_id));
    }

    public function test_the_register_lists_assets_with_their_history(): void
    {
        $keeper = $this->keeper();
        $asset = Asset::factory()->create();
        $this->actingAs($keeper)->post("/admin/assets/{$asset->id}/assign", ['user_id' => $this->staff()->id]);

        $this->actingAs($keeper)
            ->get('/admin/assets?status=assigned')
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Assets')
                ->has('assets.data', 1)
                ->has('assets.data.0.history', 1)
                ->where('counts.assigned', 1));
    }
}
