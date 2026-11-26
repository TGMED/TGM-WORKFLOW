<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\PerformanceReview;
use App\Models\Policy;
use App\Models\PublicHoliday;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The read-only API the TGM HR Agent reads staff data from: only with the
 * token, never anything private, and the same figures Workflow shows.
 */
class HrAgentApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token-for-the-hr-agent-0123456789';

    private Location $lagos;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.hr_agent.token' => self::TOKEN]);
        $this->lagos = Location::factory()->create(['name' => 'Lagos', 'workdays' => [1, 2, 3, 4, 5]]);
    }

    private function agent(string $path)
    {
        return $this->withToken(self::TOKEN)->getJson('/api/agent/'.$path);
    }

    private function person(array $attributes = []): User
    {
        return User::factory()->create(['location_id' => $this->lagos->id, ...$attributes]);
    }

    private function clockedIn(User $user, string $day, string $status, int $lateMinutes = 0): void
    {
        Attendance::query()->create([
            'user_id' => $user->id, 'location_id' => $this->lagos->id, 'work_date' => $day,
            'clocked_in_at' => $day.' 08:00:00', 'clocked_out_at' => $day.' 16:00:00',
            'status' => $status, 'late_minutes' => $lateMinutes, 'worked_minutes' => 480,
        ]);
    }

    public function test_it_is_off_without_a_token_and_refuses_a_wrong_one(): void
    {
        $this->getJson('/api/agent/staff')->assertStatus(401);
        $this->withToken('wrong')->getJson('/api/agent/staff')->assertStatus(401);

        config(['services.hr_agent.token' => '']);
        $this->withToken('anything')->getJson('/api/agent/staff')->assertStatus(503);
    }

    public function test_it_only_reads(): void
    {
        $this->withToken(self::TOKEN)->postJson('/api/agent/staff')->assertStatus(405);
        $this->withToken(self::TOKEN)->deleteJson('/api/agent/policies/1/file')->assertStatus(405);
    }

    public function test_staff_come_with_their_department_manager_and_status(): void
    {
        $manager = $this->person(['email' => 'Boss@TGM.example', 'name' => 'Bola Boss']);
        $department = Department::factory()->create(['name' => 'Admissions']);
        $this->person([
            'email' => 'ada@tgm.example', 'name' => 'Ada Obi', 'position' => 'Counsellor',
            'department_id' => $department->id, 'manager_id' => $manager->id, 'hired_at' => '2024-02-01',
        ]);
        $this->person(['email' => 'gone@tgm.example', 'is_active' => false]);

        $staff = collect($this->agent('staff')->assertOk()->json())->keyBy('email');

        $ada = $staff['ada@tgm.example'];
        $this->assertSame('Counsellor', $ada['job_title']);
        $this->assertSame('Admissions', $ada['department']);
        $this->assertSame('Lagos', $ada['location']);
        $this->assertSame('boss@tgm.example', $ada['manager_email']);
        $this->assertSame('2024-02-01', $ada['hired_on']);
        $this->assertSame('exited', $staff['gone@tgm.example']['employment_status']);
        $this->assertArrayNotHasKey('phone', $ada);
        $this->assertArrayNotHasKey('password', $ada);
    }

    public function test_leave_balances_match_the_leave_page(): void
    {
        $ada = $this->person(['email' => 'ada@tgm.example']);
        $annual = LeaveType::factory()->create(['name' => 'Annual leave', 'days_per_year' => 20]);
        LeaveRequest::factory()->create([
            'user_id' => $ada->id, 'leave_type_id' => $annual->id, 'start_date' => '2026-03-02',
            'end_date' => '2026-03-06', 'days' => 5, 'status' => RequestStatus::Approved,
        ]);

        $rows = collect($this->agent('leave-balances?year=2026')->assertOk()->json('staff')['ada@tgm.example'])
            ->keyBy('leave_type');

        $this->assertSame(['leave_type' => 'Annual leave', 'entitled_days' => 20, 'taken_days' => 5, 'remaining_days' => 15],
            $rows['Annual leave']);
    }

    public function test_attendance_counts_expected_present_late_and_leave_days(): void
    {
        $ada = $this->person(['email' => 'ada@tgm.example', 'hired_at' => '2020-01-01']);
        // Monday 2 to Friday 13 November 2026: ten weekdays, one of them a holiday.
        PublicHoliday::factory()->create(['name' => 'Founders Day', 'date' => '2026-11-04']);
        $this->clockedIn($ada, '2026-11-02', 'on_time');
        $this->clockedIn($ada, '2026-11-03', 'late', 20);
        LeaveRequest::factory()->create([
            'user_id' => $ada->id, 'leave_type_id' => LeaveType::factory(), 'start_date' => '2026-11-09',
            'end_date' => '2026-11-10', 'days' => 2, 'status' => RequestStatus::Approved,
        ]);

        $row = $this->agent('attendance?start=2026-11-02&end=2026-11-13')->assertOk()->json('staff')['ada@tgm.example'];

        $this->assertSame(['working_days' => 9, 'present_days' => 2, 'late_days' => 1, 'leave_days' => 2], $row);
    }

    public function test_someone_hired_mid_period_is_expected_only_from_their_first_day(): void
    {
        // Workflow's models hand out immutable dates; this crashed before.
        $new = $this->person(['email' => 'new@tgm.example', 'hired_at' => '2026-11-11']);
        $this->clockedIn($new, '2026-11-11', 'on_time');
        LeaveRequest::factory()->create([
            'user_id' => $new->id, 'leave_type_id' => LeaveType::factory(), 'start_date' => '2026-11-12',
            'end_date' => '2026-11-13', 'days' => 2, 'status' => RequestStatus::Approved,
        ]);

        $row = $this->agent('attendance?start=2026-11-02&end=2026-11-13')->assertOk()->json('staff')['new@tgm.example'];

        $this->assertSame(['working_days' => 3, 'present_days' => 1, 'late_days' => 0, 'leave_days' => 2], $row);
    }

    public function test_today_counts_only_once_they_have_clocked_in_and_days_to_come_never(): void
    {
        $this->travelTo(Carbon::parse('2026-11-26 08:00')); // a Thursday
        $early = $this->person(['email' => 'early@tgm.example', 'hired_at' => '2020-01-01']);
        $late = $this->person(['email' => 'late@tgm.example', 'hired_at' => '2020-01-01']);
        foreach (['2026-11-23', '2026-11-24', '2026-11-25'] as $day) {
            $this->clockedIn($early, $day, 'on_time');
            $this->clockedIn($late, $day, 'on_time');
        }
        $this->clockedIn($early, '2026-11-26', 'on_time');

        $staff = $this->agent('attendance?start=2026-11-23&end=2026-11-29')->assertOk()->json('staff');

        $this->assertSame([4, 4], [$staff['early@tgm.example']['working_days'], $staff['early@tgm.example']['present_days']]);
        $this->assertSame([3, 3], [$staff['late@tgm.example']['working_days'], $staff['late@tgm.example']['present_days']]);
    }

    public function test_absences_give_dates_and_kind_but_never_the_reason(): void
    {
        $ada = $this->person(['email' => 'ada@tgm.example']);
        LeaveRequest::factory()->create([
            'user_id' => $ada->id, 'leave_type_id' => LeaveType::factory()->create(['name' => 'Sick leave']),
            'start_date' => '2026-11-09', 'end_date' => '2026-11-10', 'days' => 2,
            'status' => RequestStatus::Approved, 'reason' => 'Private medical detail',
        ]);
        LeaveRequest::factory()->create([
            'user_id' => $ada->id, 'leave_type_id' => LeaveType::factory(), 'start_date' => '2026-11-11',
            'end_date' => '2026-11-11', 'days' => 1, 'status' => RequestStatus::Pending,
        ]);

        $response = $this->agent('absences?start=2026-11-01&end=2026-11-30')->assertOk();

        $this->assertSame([['email' => 'ada@tgm.example', 'start' => '2026-11-09', 'end' => '2026-11-10', 'kind' => 'sick leave']],
            $response->json());
        $this->assertStringNotContainsString('Private medical', $response->getContent());
    }

    public function test_absences_survive_a_retired_leave_type_but_not_a_removed_person(): void
    {
        $ada = $this->person(['email' => 'ada@tgm.example']);
        $removed = $this->person(['email' => 'removed@tgm.example']);
        $old = LeaveType::factory()->create(['name' => 'Study leave']);
        foreach ([$ada, $removed] as $who) {
            LeaveRequest::factory()->create([
                'user_id' => $who->id, 'leave_type_id' => $old->id, 'start_date' => '2026-11-09',
                'end_date' => '2026-11-09', 'days' => 1, 'status' => RequestStatus::Approved,
            ]);
        }
        $old->delete();
        $removed->delete();

        $this->agent('absences?start=2026-11-01&end=2026-11-30')->assertOk()->assertExactJson([
            ['email' => 'ada@tgm.example', 'start' => '2026-11-09', 'end' => '2026-11-09', 'kind' => 'study leave'],
        ]);
    }

    public function test_reviews_are_public_ones_only_and_never_name_the_author(): void
    {
        $ada = $this->person(['email' => 'ada@tgm.example']);
        $author = $this->person(['name' => 'Secret Author', 'email' => 'author@tgm.example']);
        PerformanceReview::factory()->create(['subject_user_id' => $ada->id, 'reviewer_id' => $author->id, 'rating' => 4, 'body' => 'Great with students']);
        PerformanceReview::factory()->create(['subject_user_id' => $ada->id, 'reviewer_id' => $author->id, 'rating' => 2, 'body' => 'Kind words']);
        PerformanceReview::factory()->private()->create(['subject_user_id' => $ada->id, 'reviewer_id' => $author->id, 'rating' => 1, 'body' => 'Private note']);

        $response = $this->agent('reviews')->assertOk();
        $row = $response->json()['ada@tgm.example'];

        $this->assertSame(2, $row['review_count']);
        $this->assertEquals(3.0, $row['average_rating']);
        $text = $response->getContent();
        foreach (['Secret Author', 'author@tgm.example', 'reviewer', 'Private note'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $text);
        }
    }

    public function test_policies_in_force_can_be_listed_and_read(): void
    {
        Storage::fake('local');
        $path = UploadedFile::fake()->createWithContent('leave.txt', 'Annual leave needs ten days notice.')->store('policies', 'local');
        $policy = Policy::factory()->create(['title' => 'Leave policy', 'file_path' => $path, 'file_name' => 'leave.txt', 'mime_type' => 'text/plain']);
        $retired = Policy::factory()->create(['title' => 'Old policy', 'is_active' => false]);
        Policy::factory()->create(['title' => 'Future policy', 'effective_from' => now()->addMonth()->toDateString()]);

        $titles = collect($this->agent('policies')->assertOk()->json())->pluck('title')->all();
        $this->assertSame(['Leave policy'], $titles);

        $file = $this->withToken(self::TOKEN)->get("/api/agent/policies/{$policy->id}/file")->assertOk();
        $this->assertStringContainsString('ten days notice', $file->streamedContent());
        $this->withToken(self::TOKEN)->get("/api/agent/policies/{$retired->id}/file")->assertNotFound()->assertJsonPath('error', 'No policy in force with that id.');
        $this->withToken(self::TOKEN)->get('/api/agent/policies/99999/file')->assertNotFound();
    }

    public function test_holidays_list_the_sites_that_keep_them(): void
    {
        $holiday = PublicHoliday::factory()->create(['name' => 'Lagos Day', 'date' => '2026-05-27']);
        $holiday->locations()->attach($this->lagos);
        PublicHoliday::factory()->create(['name' => 'Christmas Day', 'date' => '2026-12-25']);

        $this->agent('holidays?year=2026')->assertOk()->assertExactJson([
            ['date' => '2026-05-27', 'name' => 'Lagos Day', 'locations' => ['Lagos']],
            ['date' => '2026-12-25', 'name' => 'Christmas Day', 'locations' => []],
        ]);
    }

    public function test_bad_dates_are_explained(): void
    {
        $this->agent('attendance?start=nope&end=2026-11-01')->assertStatus(400)->assertJsonPath('error', 'start and end must be dates written YYYY-MM-DD.');
        $this->agent('absences?start=2026-11-10&end=2026-11-01')->assertStatus(400);
        $this->agent('attendance?start=2020-01-01&end=2026-01-01')->assertStatus(400);
        $this->agent('holidays?year=99')->assertStatus(400);
    }
}
