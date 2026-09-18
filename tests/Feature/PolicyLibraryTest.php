<?php

namespace Tests\Feature;

use App\Enums\PolicyCategory;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The handbook and the policies under it: published by the people team, read
 * by everybody, versioned rather than overwritten.
 */
class PolicyLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Employee handbook',
            'category' => PolicyCategory::Handbook->value,
            'version' => '1.0',
            'summary' => 'Everything else hangs off this one.',
            'effective_from' => Carbon::now()->toDateString(),
            'document' => UploadedFile::fake()->create('handbook.pdf', 200, 'application/pdf'),
            ...$overrides,
        ];
    }

    public function test_the_people_team_can_publish_a_policy(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/policies', $this->payload())
            ->assertSessionHasNoErrors();

        $policy = Policy::query()->firstOrFail();

        $this->assertSame('Employee handbook', $policy->title);
        $this->assertSame(PolicyCategory::Handbook, $policy->category);
        $this->assertTrue($policy->is_active);
        Storage::disk('local')->assertExists($policy->file_path);
    }

    public function test_the_document_is_required_and_has_to_be_a_document(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/policies', $this->payload(['document' => null]))
            ->assertSessionHasErrors('document');

        $this->actingAs($admin)
            ->post('/admin/policies', $this->payload([
                'document' => UploadedFile::fake()->create('policy.exe', 10),
            ]))
            ->assertSessionHasErrors('document');
    }

    public function test_publishing_a_new_version_retires_the_one_it_replaces(): void
    {
        $old = Policy::factory()->create(['title' => 'Handbook', 'version' => '1.0']);

        $this->actingAs($this->admin())
            ->post('/admin/policies', $this->payload([
                'version' => '2.0',
                'supersedes_id' => $old->id,
            ]))
            ->assertSessionHasNoErrors();

        $new = Policy::query()->where('version', '2.0')->firstOrFail();

        $this->assertFalse($old->refresh()->is_active);
        $this->assertSame($old->id, $new->supersedes_id);
        $this->assertTrue($new->is_active);
    }

    public function test_an_already_retired_policy_cannot_be_superseded(): void
    {
        $retired = Policy::factory()->retired()->create();

        $this->actingAs($this->admin())
            ->post('/admin/policies', $this->payload(['supersedes_id' => $retired->id]))
            ->assertSessionHasErrors('supersedes_id');
    }

    public function test_staff_see_what_is_in_force_and_nothing_else(): void
    {
        $inForce = Policy::factory()->create(['title' => 'Code of conduct']);
        Policy::factory()->retired()->create(['title' => 'Old conduct rules']);
        Policy::factory()->upcoming()->create(['title' => 'Next year rules']);

        $this->actingAs(User::factory()->create())
            ->get('/policies')
            ->assertOk()
            ->assertInertia(function ($page) use ($inForce) {
                $props = $page->toArray()['props'];

                $this->assertSame(1, $props['total']);

                $titles = collect($props['groups'])
                    ->flatMap(fn (array $group): array => $group['policies'])
                    ->pluck('title');

                $this->assertTrue($titles->contains($inForce->title));
                $this->assertFalse($titles->contains('Old conduct rules'));
                $this->assertFalse($titles->contains('Next year rules'));
            });
    }

    public function test_the_document_is_served_from_behind_a_controller(): void
    {
        Storage::disk('local')->put('policies/handbook.pdf', 'the rules');

        $policy = Policy::factory()->create([
            'file_path' => 'policies/handbook.pdf',
            'file_name' => 'handbook.pdf',
        ]);

        $this->actingAs(User::factory()->create())
            ->get("/policies/{$policy->id}/file")
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=handbook.pdf');
    }

    public function test_a_retired_document_is_not_served_to_staff(): void
    {
        $policy = Policy::factory()->retired()->create();

        $this->actingAs(User::factory()->create())
            ->get("/policies/{$policy->id}/file")
            ->assertNotFound();
    }

    public function test_a_policy_can_be_retired_and_put_back(): void
    {
        $admin = $this->admin();
        $policy = Policy::factory()->create();

        $this->actingAs($admin)->patch("/admin/policies/{$policy->id}/retire");
        $this->assertFalse($policy->refresh()->is_active);

        $this->actingAs($admin)->patch("/admin/policies/{$policy->id}/restore");
        $this->assertTrue($policy->refresh()->is_active);
    }

    public function test_staff_cannot_publish_or_retire(): void
    {
        $staff = User::factory()->create();
        $policy = Policy::factory()->create();

        $this->actingAs($staff)
            ->post('/admin/policies', $this->payload())
            ->assertForbidden();

        $this->actingAs($staff)
            ->patch("/admin/policies/{$policy->id}/retire")
            ->assertForbidden();

        $this->actingAs($staff)
            ->get('/admin/policies')
            ->assertForbidden();
    }
}
