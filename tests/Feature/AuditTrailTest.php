<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\Audit;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the trail keeps, and who may read it.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * @param  array<int, Permission>  $permissions
     */
    private function staffWith(array $permissions = []): User
    {
        $role = Role::query()->create([
            'slug' => 'custom_'.Role::query()->count(),
            'name' => 'Custom',
            'is_system' => false,
        ]);

        $role->syncPermissions($permissions);

        return User::factory()->create([
            'role_id' => $role->id,
            'location_id' => Location::factory()->create()->id,
        ]);
    }

    // What gets written.

    public function test_a_change_is_recorded_with_who_made_it(): void
    {
        $admin = $this->admin();
        $site = Location::factory()->create(['name' => 'Ikeja']);

        $this->actingAs($admin);

        $site->update(['name' => 'Ikeja Annex']);

        $audit = Audit::query()->where('auditable_type', Location::class)->latest('id')->firstOrFail();

        $this->assertSame('updated', $audit->event);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('Ikeja', $audit->old_values['name']);
        $this->assertSame('Ikeja Annex', $audit->new_values['name']);
    }

    public function test_creating_and_deleting_are_both_recorded(): void
    {
        $this->actingAs($this->admin());

        $role = Role::query()->create(['slug' => 'temp', 'name' => 'Temp', 'is_system' => false]);
        $role->delete();

        $events = Audit::query()
            ->where('auditable_type', Role::class)
            ->pluck('event')
            ->all();

        $this->assertContains('created', $events);
        $this->assertContains('deleted', $events);
    }

    public function test_a_password_never_reaches_the_trail(): void
    {
        $this->actingAs($this->admin());

        // Pinned, so the update below is always a real change: the factory
        // picks a department at random, and setting one to what it already
        // says would leave nothing for the trail to record.
        $staff = User::factory()->create(['department' => 'Operations']);

        $staff->update(['password' => 'a-brand-new-secret', 'department' => 'Finance']);

        $audit = Audit::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $staff->id)
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertArrayNotHasKey('password', $audit->new_values);
        $this->assertArrayNotHasKey('password', $audit->old_values);
        $this->assertSame('Finance', $audit->new_values['department']);
    }

    public function test_a_change_made_with_nobody_signed_in_records_no_actor(): void
    {
        Location::factory()->create(['name' => 'Seeded site']);

        $audit = Audit::query()->where('auditable_type', Location::class)->latest('id')->firstOrFail();

        $this->assertNull($audit->user_id);
    }

    // Who may read it.

    public function test_the_trail_is_behind_its_own_permission(): void
    {
        $this->actingAs($this->staffWith())->get('/admin/audit')->assertForbidden();
        $this->actingAs($this->staffWith([Permission::ViewAuditTrail]))->get('/admin/audit')->assertOk();
    }

    public function test_the_trail_lists_what_changed(): void
    {
        $admin = $this->admin();
        $site = Location::factory()->create(['name' => 'Ikeja']);

        $this->actingAs($admin);
        $site->update(['name' => 'Ikeja Annex']);

        $this->get('/admin/audit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/Audit')
                ->where('audits.data.0.type_label', 'Location')
                ->where('audits.data.0.subject', 'Ikeja Annex')
                ->where('audits.data.0.actor', $admin->name)
                ->where(
                    'audits.data.0.changes',
                    fn ($changes): bool => collect($changes)
                        ->contains(fn (array $change): bool => $change['label'] === 'Name'
                            && $change['from'] === 'Ikeja'
                            && $change['to'] === 'Ikeja Annex'),
                )
                ->etc());
    }

    public function test_the_trail_can_be_filtered_by_event(): void
    {
        $this->actingAs($this->admin());

        Location::factory()->create(['name' => 'Made']);
        Location::factory()->create(['name' => 'Changed'])->update(['name' => 'Changed again']);

        $this->get('/admin/audit?event=updated')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where(
                    'audits.data',
                    fn ($rows): bool => collect($rows)->every(
                        fn (array $row): bool => $row['event'] === 'updated',
                    ) && collect($rows)->isNotEmpty(),
                )
                ->etc());
    }

    public function test_the_trail_can_be_filtered_by_who_made_the_change(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        $this->actingAs($admin);
        Location::factory()->create(['name' => 'Mine']);

        $this->actingAs($other);
        Location::factory()->create(['name' => 'Theirs']);

        $this->actingAs($admin)
            ->get("/admin/audit?user={$admin->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where(
                    'audits.data',
                    fn ($rows): bool => collect($rows)->every(
                        fn (array $row): bool => $row['actor_id'] === $admin->id,
                    ),
                )
                ->etc());
    }

    public function test_there_is_no_way_to_write_to_the_trail(): void
    {
        $admin = $this->admin();

        // Read-only by design: nothing but the index is routed, so neither a
        // write nor a delete finds anything to reach.
        $this->actingAs($admin)->post('/admin/audit')->assertMethodNotAllowed();
        $this->actingAs($admin)->delete('/admin/audit/1')->assertNotFound();
    }
}
