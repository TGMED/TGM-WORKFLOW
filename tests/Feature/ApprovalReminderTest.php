<?php

namespace Tests\Feature;

use App\Enums\NotificationTopic;
use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\User;
use App\Notifications\PendingApprovalsReminder;
use App\Services\ApprovalReminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Each working morning approvers hear what is waiting on them, and super
 * admins get the list of everything still pending.
 */
class ApprovalReminderTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();

        config(['hr.approval_reminders.enabled' => true, 'hr.approval_reminders.after_hours' => 24]);
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->create(['location_id' => $this->location->id, ...$attributes]);
    }

    private function pending(User $requester, int $hoursOld = 30, RequestStatus $status = RequestStatus::Pending): LeaveRequest
    {
        $leave = LeaveRequest::factory()->create(['user_id' => $requester->id, 'status' => $status]);

        $leave->forceFill(['created_at' => Carbon::now()->subHours($hoursOld)])->save();

        return $leave->refresh();
    }

    public function test_approvers_and_super_admins_are_reminded_of_requests_left_waiting(): void
    {
        Notification::fake();

        $admin = User::factory()->superAdmin()->create();
        $approver = User::factory()->approver()->create(['location_id' => $this->location->id]);
        $requester = $this->staff(['name' => 'Ada Obi']);

        $this->pending($requester);

        $sent = app(ApprovalReminders::class)->run();

        $this->assertSame(1, $sent['requests']);

        Notification::assertSentTo($approver, PendingApprovalsReminder::class,
            fn (PendingApprovalsReminder $n): bool => $n->topic() === NotificationTopic::ApprovalRequested);
        Notification::assertSentTo($admin, PendingApprovalsReminder::class,
            fn (PendingApprovalsReminder $n): bool => $n->topic() === NotificationTopic::ApprovalReminder);
        Notification::assertNotSentTo($requester, PendingApprovalsReminder::class);
    }

    public function test_each_approver_gets_one_email_however_many_requests_wait(): void
    {
        Notification::fake();

        $approver = User::factory()->approver()->create(['location_id' => $this->location->id]);

        $this->pending($this->staff());
        $this->pending($this->staff());
        $this->pending($this->staff());

        app(ApprovalReminders::class)->run();

        Notification::assertSentToTimes($approver, PendingApprovalsReminder::class, 1);

        $mail = Notification::sent($approver, PendingApprovalsReminder::class)->first()->toMail($approver);
        $this->assertSame('Reminder: 3 requests waiting for your decision', $mail->subject);
    }

    public function test_fresh_and_decided_requests_are_left_alone(): void
    {
        Notification::fake();

        $admin = User::factory()->superAdmin()->create();
        $approver = User::factory()->approver()->create(['location_id' => $this->location->id]);

        $this->pending($this->staff(), hoursOld: 3);
        $this->pending($this->staff(), status: RequestStatus::Approved);
        $this->pending($this->staff(), status: RequestStatus::Rejected);

        $sent = app(ApprovalReminders::class)->run();

        $this->assertSame(0, $sent['requests']);
        Notification::assertNothingSent();
    }

    public function test_a_super_admin_is_not_reminded_of_their_own_request(): void
    {
        Notification::fake();

        $admin = User::factory()->superAdmin()->create(['location_id' => $this->location->id]);
        $approver = User::factory()->approver()->create(['location_id' => $this->location->id]);

        $this->pending($admin);

        app(ApprovalReminders::class)->run();

        Notification::assertSentTo($approver, PendingApprovalsReminder::class);
        Notification::assertNotSentTo($admin, PendingApprovalsReminder::class);
    }

    public function test_the_email_names_the_requester_and_who_it_is_with(): void
    {
        Notification::fake();

        $admin = User::factory()->superAdmin()->create(['name' => 'Chidi Okafor']);
        User::factory()->approver()->create(['location_id' => $this->location->id, 'name' => 'Bola Ade']);

        $this->pending($this->staff(['name' => 'Ada Obi']), hoursOld: 72);

        app(ApprovalReminders::class)->run();

        $html = (string) Notification::sent($admin, PendingApprovalsReminder::class)
            ->first()
            ->toMail($admin)
            ->render();

        $this->assertStringContainsString('Ada Obi', $html);
        $this->assertStringContainsString('Waiting 3 days', $html);
        $this->assertStringContainsString('Bola Ade', $html);
    }

    public function test_the_command_does_nothing_when_switched_off(): void
    {
        Notification::fake();

        config(['hr.approval_reminders.enabled' => false]);

        User::factory()->approver()->create(['location_id' => $this->location->id]);
        $this->pending($this->staff());

        $this->artisan('approvals:remind')
            ->expectsOutput('Approval reminders are switched off.')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_reminder_is_scheduled_on_weekday_mornings(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('approvals:remind')
            ->assertSuccessful();
    }
}
