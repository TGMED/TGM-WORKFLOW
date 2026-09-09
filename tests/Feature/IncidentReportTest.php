<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\ReportCategory;
use App\Enums\ReportStatus;
use App\Models\Location;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use App\Services\ReportEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Reporting is confidential rather than anonymous: the reporter is recorded,
 * and the point of nearly every test here is that the record goes no further
 * than the reports desk.
 */
class IncidentReportTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create([
            'location_id' => Location::factory()->create()->id,
        ]);
    }

    private function handler(): User
    {
        $role = Role::query()->create([
            'slug' => 'people_officer_'.Role::query()->count(),
            'name' => 'People officer',
            'is_system' => false,
        ]);

        $role->syncPermissions([Permission::HandleReports]);

        return User::factory()->roles($role->slug)->create([
            'location_id' => Location::factory()->create()->id,
        ]);
    }

    // Filing.

    public function test_a_member_of_staff_can_file_a_report(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->post('/reports', [
                'category' => ReportCategory::Harassment->value,
                'subject' => 'Comments in the stockroom',
                'body' => 'This has been going on for three weeks and I want it to stop.',
            ])
            ->assertSessionHasNoErrors();

        $report = Report::query()->firstOrFail();

        $this->assertSame($staff->id, $report->user_id);
        $this->assertSame(ReportStatus::Submitted, $report->status);
        $this->assertNull($report->subject_user_id);
    }

    public function test_the_reporter_is_taken_from_the_session_not_the_form(): void
    {
        $staff = $this->staff();
        $someoneElse = $this->staff();

        $this->actingAs($staff)
            ->post('/reports', [
                // A forged reporter is ignored: `user_id` is not input.
                'user_id' => $someoneElse->id,
                'category' => ReportCategory::Safety->value,
                'subject' => 'Unguarded machine',
                'body' => 'The guard has been off the press since Monday morning.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($staff->id, Report::query()->firstOrFail()->user_id);
    }

    public function test_a_report_needs_enough_detail_to_act_on(): void
    {
        $this->actingAs($this->staff())
            ->post('/reports', [
                'category' => ReportCategory::Other->value,
                'subject' => 'Something',
                'body' => 'Too short.',
            ])
            ->assertSessionHasErrors('body');
    }

    public function test_nobody_can_report_themselves(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->post('/reports', [
                'category' => ReportCategory::Misconduct->value,
                'subject' => 'A report about me',
                'body' => 'This should not be allowed to be filed against myself.',
                'subject_user_id' => $staff->id,
            ])
            ->assertSessionHasErrors('subject_user_id');
    }

    public function test_a_typed_name_is_dropped_when_a_colleague_is_picked(): void
    {
        $subject = $this->staff();

        $this->actingAs($this->staff())
            ->post('/reports', [
                'category' => ReportCategory::Bullying->value,
                'subject' => 'Repeated remarks in meetings',
                'body' => 'It happens every stand-up and other people have noticed it too.',
                'subject_user_id' => $subject->id,
                'subject_name' => 'Somebody else entirely',
            ])
            ->assertSessionHasNoErrors();

        $report = Report::query()->firstOrFail();

        $this->assertSame($subject->id, $report->subject_user_id);
        $this->assertNull($report->subject_name);
    }

    public function test_a_report_can_name_someone_who_is_not_on_the_staff_list(): void
    {
        $this->actingAs($this->staff())
            ->post('/reports', [
                'category' => ReportCategory::Misconduct->value,
                'subject' => 'A contractor on the second floor',
                'body' => 'The lift engineer was abusive to reception on Tuesday afternoon.',
                'subject_name' => 'The lift engineer',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('The lift engineer', Report::query()->firstOrFail()->subject_name);
    }

    // Who can read what.

    public function test_a_reporter_sees_their_own_reports_and_nobody_elses(): void
    {
        $staff = $this->staff();

        $mine = Report::factory()->create(['user_id' => $staff->id]);
        $theirs = Report::factory()->create(['user_id' => $this->staff()->id]);

        $this->actingAs($staff)
            ->get('/reports')
            ->assertInertia(fn ($page) => $page
                ->component('Reports')
                ->has('reports', 1)
                ->where('reports.0.id', $mine->id));

        $this->assertNotSame($mine->id, $theirs->id);
    }

    public function test_the_reports_desk_is_shut_to_a_role_without_the_permission(): void
    {
        $this->actingAs($this->staff())
            ->get('/admin/reports')
            ->assertForbidden();
    }

    public function test_a_handler_reads_the_case_and_the_reporters_name(): void
    {
        $staff = $this->staff();

        Report::factory()->create([
            'user_id' => $staff->id,
            'subject' => 'Comments in the stockroom',
        ]);

        $this->actingAs($this->handler())
            ->get('/admin/reports')
            ->assertInertia(fn ($page) => $page
                ->component('admin/Reports')
                ->has('reports.data', 1)
                ->where('reports.data.0.reporter.name', $staff->name));
    }

    public function test_the_person_reported_is_told_nothing(): void
    {
        $subject = $this->staff();

        Report::factory()->about($subject)->create([
            'user_id' => $this->staff()->id,
        ]);

        // Being the subject of a report grants no access to it at all: the
        // reports page shows this person their own reports, which is none.
        $this->actingAs($subject)
            ->get('/reports')
            ->assertInertia(fn ($page) => $page->has('reports', 0));

        $this->actingAs($subject)
            ->get('/admin/reports')
            ->assertForbidden();
    }

    // Evidence.

    public function test_an_attachment_is_stored_privately_and_stripped_of_its_filename(): void
    {
        Storage::fake(ReportEvidence::DISK);

        $staff = $this->staff();

        $this->actingAs($staff)
            ->post('/reports', [
                'category' => ReportCategory::Harassment->value,
                'subject' => 'Messages I was sent',
                'body' => 'Screenshots of the messages are attached to this report.',
                'evidence' => UploadedFile::fake()->image('my-name-and-phone-number.jpg'),
            ])
            ->assertSessionHasNoErrors();

        $report = Report::query()->firstOrFail();

        $this->assertTrue($report->hasEvidence());
        Storage::disk(ReportEvidence::DISK)->assertExists($report->evidence_path);

        // The original filename could name the reporter, so only the
        // extension survives.
        $this->assertSame('evidence.jpg', $report->evidence_name);
    }

    public function test_evidence_opens_for_the_reporter_and_the_desk_but_nobody_else(): void
    {
        Storage::fake(ReportEvidence::DISK);

        $staff = $this->staff();
        $subject = $this->staff();

        $report = Report::factory()->about($subject)->create(['user_id' => $staff->id]);

        Storage::disk(ReportEvidence::DISK)->put('report-evidence/note.pdf', 'contents');
        $report->update(['evidence_path' => 'report-evidence/note.pdf', 'evidence_name' => 'evidence.pdf']);

        $this->actingAs($staff)->get("/reports/{$report->id}/evidence")->assertOk();
        $this->actingAs($this->handler())->get("/reports/{$report->id}/evidence")->assertOk();

        $this->actingAs($subject)->get("/reports/{$report->id}/evidence")->assertForbidden();
        $this->actingAs($this->staff())->get("/reports/{$report->id}/evidence")->assertForbidden();
    }

    // Handling.

    public function test_a_handler_moves_a_case_along_and_is_stamped_on_it(): void
    {
        $handler = $this->handler();
        $report = Report::factory()->create(['user_id' => $this->staff()->id]);

        $this->actingAs($handler)
            ->put("/admin/reports/{$report->id}", [
                'status' => ReportStatus::UnderReview->value,
            ])
            ->assertSessionHasNoErrors();

        $report->refresh();

        $this->assertSame(ReportStatus::UnderReview, $report->status);
        $this->assertSame($handler->id, $report->handled_by_id);
        // Only a closed case carries a handled-at: the timestamp measures how
        // long the reporter waited for an answer.
        $this->assertNull($report->handled_at);
    }

    public function test_closing_a_case_asks_for_a_reason(): void
    {
        $report = Report::factory()->create(['user_id' => $this->staff()->id]);

        $this->actingAs($this->handler())
            ->put("/admin/reports/{$report->id}", [
                'status' => ReportStatus::Resolved->value,
            ])
            ->assertSessionHasErrors('resolution_note');

        $this->assertSame(ReportStatus::Submitted, $report->refresh()->status);
    }

    public function test_a_closed_case_is_stamped_and_the_reporter_sees_the_standing_not_the_note(): void
    {
        $staff = $this->staff();
        $report = Report::factory()->create(['user_id' => $staff->id]);

        $this->actingAs($this->handler())
            ->put("/admin/reports/{$report->id}", [
                'status' => ReportStatus::Resolved->value,
                'resolution_note' => 'Spoken to, and moved to another team.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($report->refresh()->handled_at);

        $this->actingAs($staff)
            ->get('/reports')
            ->assertInertia(fn ($page) => $page
                ->where('reports.0.status_label', 'Resolved')
                ->missing('reports.0.resolution_note'));
    }

    public function test_a_member_of_staff_cannot_close_their_own_report(): void
    {
        $staff = $this->staff();
        $report = Report::factory()->create(['user_id' => $staff->id]);

        $this->actingAs($staff)
            ->put("/admin/reports/{$report->id}", [
                'status' => ReportStatus::Dismissed->value,
                'resolution_note' => 'Nothing to see here.',
            ])
            ->assertForbidden();
    }

    // Ordering.

    public function test_urgent_categories_come_first_in_the_open_list(): void
    {
        $routine = Report::factory()
            ->category(ReportCategory::Misconduct)
            ->create(['user_id' => $this->staff()->id, 'created_at' => now()->subWeek()]);

        $urgent = Report::factory()
            ->category(ReportCategory::Harassment)
            ->create(['user_id' => $this->staff()->id, 'created_at' => now()]);

        $this->actingAs($this->handler())
            ->get('/admin/reports')
            ->assertInertia(fn ($page) => $page
                // Newer, but a priority category, so it sorts above the older
                // routine case.
                ->where('reports.data.0.id', $urgent->id)
                ->where('reports.data.1.id', $routine->id));
    }
}
