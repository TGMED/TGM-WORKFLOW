<?php

namespace Tests\Feature;

use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Models\ApprovalSetting;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    public function test_leave_starts_at_one_approver(): void
    {
        $this->assertSame(1, ApprovalSetting::approversRequired(RequestModule::Leave));
    }

    public function test_super_admins_can_change_how_many_approvers_leave_needs(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/request-settings/leave', ['approvers_required' => 3])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, ApprovalSetting::approversRequired(RequestModule::Leave));
        // Lateness is configured separately and is left alone.
        $this->assertSame(1, ApprovalSetting::approversRequired(RequestModule::Lateness));
    }

    public function test_the_approver_count_has_bounds(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/request-settings/leave', ['approvers_required' => 0])
            ->assertSessionHasErrors('approvers_required');

        $this->actingAs($this->admin())
            ->put('/admin/request-settings/leave', ['approvers_required' => 9])
            ->assertSessionHasErrors('approvers_required');
    }

    public function test_an_unknown_module_is_not_configurable(): void
    {
        $this->actingAs($this->admin())
            ->put('/admin/request-settings/overtime', ['approvers_required' => 2])
            ->assertNotFound();
    }

    public function test_staff_cannot_reach_the_settings_page(): void
    {
        $staff = User::factory()->create(['location_id' => Location::factory()->create()->id]);

        $this->actingAs($staff)->get('/admin/request-settings')->assertForbidden();
        $this->actingAs($staff)
            ->put('/admin/request-settings/leave', ['approvers_required' => 5])
            ->assertForbidden();
    }

    public function test_super_admins_can_add_a_leave_type(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/leave-types', [
                'name' => 'Sabbatical leave',
                'description' => 'An extended break agreed with the company.',
                'days_per_year' => 5,
                'is_paid' => true,
            ])
            ->assertSessionHasNoErrors();

        $type = LeaveType::query()->where('name', 'Sabbatical leave')->firstOrFail();

        $this->assertSame('sabbatical-leave', $type->slug);
        $this->assertSame(5, $type->days_per_year);
        $this->assertTrue($type->is_active);
    }

    public function test_a_leave_type_can_be_uncapped(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/leave-types', [
                'name' => 'Unpaid leave',
                'days_per_year' => null,
                'is_paid' => false,
            ])
            ->assertSessionHasNoErrors();

        $type = LeaveType::query()->where('name', 'Unpaid leave')->firstOrFail();

        $this->assertNull($type->days_per_year);
        $this->assertFalse($type->isCapped());
    }

    public function test_leave_type_names_are_unique(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/leave-types', ['name' => 'Annual leave'])
            ->assertSessionHasErrors('name');
    }

    public function test_super_admins_can_edit_a_leave_type(): void
    {
        $type = LeaveType::query()->where('slug', 'annual')->firstOrFail();

        $this->actingAs($this->admin())
            ->put("/admin/leave-types/{$type->id}", [
                'name' => 'Annual leave',
                'description' => 'Paid time off.',
                'days_per_year' => 25,
                'is_paid' => true,
            ])
            ->assertSessionHasNoErrors();

        $type->refresh();

        // The slug is fixed at creation so nothing keyed on it is orphaned.
        $this->assertSame('annual', $type->slug);
        $this->assertSame(25, $type->days_per_year);
    }

    public function test_a_retired_type_disappears_from_the_request_form(): void
    {
        $type = LeaveType::factory()->create();

        $this->actingAs($this->admin())
            ->patch("/admin/leave-types/{$type->id}/toggle")
            ->assertSessionHasNoErrors();

        $this->assertFalse($type->refresh()->is_active);
        $this->assertNotContains(
            $type->id,
            LeaveType::query()->active()->pluck('id')->all(),
        );
    }

    public function test_a_type_with_requests_awaiting_a_decision_cannot_be_retired(): void
    {
        $type = LeaveType::factory()->create();

        LeaveRequest::factory()->create([
            'leave_type_id' => $type->id,
            'status' => RequestStatus::Pending,
        ]);

        $this->actingAs($this->admin())
            ->patch("/admin/leave-types/{$type->id}/toggle");

        $this->assertTrue($type->refresh()->is_active);
    }

    public function test_staff_cannot_manage_leave_types(): void
    {
        $staff = User::factory()->create(['location_id' => Location::factory()->create()->id]);

        $this->actingAs($staff)
            ->post('/admin/leave-types', ['name' => 'Sneaky leave'])
            ->assertForbidden();
    }

    public function test_the_settings_page_lists_modules_and_types(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/request-settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/RequestSettings')
                ->has('modules', 2)
                ->has('leave_types', 11)
                ->where('modules.0.value', 'leave'));
    }
}
