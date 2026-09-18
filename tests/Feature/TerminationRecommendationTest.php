<?php

namespace Tests\Feature;

use App\Enums\RecommendationStatus;
use App\Models\Department;
use App\Models\Location;
use App\Models\Offence;
use App\Models\TerminationRecommendation;
use App\Models\User;
use App\Notifications\RecommendationDecided;
use App\Notifications\TerminationRecommended;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A senior member of staff putting it to the people team that somebody who
 * answers to them should be let go.
 */
class TerminationRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->create([
            'location_id' => $this->location->id,
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $subject, array $overrides = []): array
    {
        return [
            'subject_user_id' => $subject->id,
            'occurrence' => 1,
            'grounds' => 'Three unexplained absences this quarter, each one raised with them in writing at the time.',
            ...$overrides,
        ];
    }

    public function test_a_manager_can_raise_one_about_somebody_who_reports_to_them(): void
    {
        Notification::fake();

        $hr = User::factory()->superAdmin()->create();
        $manager = $this->staff();
        $junior = $this->staff(['manager_id' => $manager->id]);

        $this->actingAs($manager)
            ->post('/recommendations', $this->payload($junior))
            ->assertSessionHasNoErrors();

        $recommendation = TerminationRecommendation::query()->firstOrFail();

        $this->assertSame($junior->id, $recommendation->subject_user_id);
        $this->assertSame($manager->id, $recommendation->raised_by_id);
        $this->assertSame(RecommendationStatus::Pending, $recommendation->status);

        Notification::assertSentTo($hr, TerminationRecommended::class);
    }

    public function test_a_head_of_department_can_raise_one_about_their_own_people(): void
    {
        Notification::fake();
        User::factory()->superAdmin()->create();

        $department = Department::factory()->create();
        $head = $this->staff(['department_id' => $department->id]);
        $department->update(['head_user_id' => $head->id]);

        $member = $this->staff(['department_id' => $department->id]);

        $this->actingAs($head->refresh())
            ->post('/recommendations', $this->payload($member))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, TerminationRecommendation::query()->count());
    }

    public function test_nobody_can_raise_one_about_a_colleague_who_does_not_answer_to_them(): void
    {
        User::factory()->superAdmin()->create();

        $manager = $this->staff();
        $this->staff(['manager_id' => $manager->id]);
        $stranger = $this->staff();

        $this->actingAs($manager)
            ->post('/recommendations', $this->payload($stranger))
            ->assertSessionHasErrors('subject_user_id');

        $this->assertSame(0, TerminationRecommendation::query()->count());
    }

    public function test_somebody_with_nobody_under_them_cannot_raise_one_at_all(): void
    {
        $staff = $this->staff();
        $colleague = $this->staff();

        $this->actingAs($staff)
            ->post('/recommendations', $this->payload($colleague))
            ->assertForbidden();
    }

    public function test_the_case_has_to_be_set_out_properly(): void
    {
        $manager = $this->staff();
        $junior = $this->staff(['manager_id' => $manager->id]);

        $this->actingAs($manager)
            ->post('/recommendations', $this->payload($junior, ['grounds' => 'He is rude.']))
            ->assertSessionHasErrors('grounds');
    }

    public function test_two_cannot_be_open_about_the_same_person(): void
    {
        Notification::fake();
        User::factory()->superAdmin()->create();

        $manager = $this->staff();
        $junior = $this->staff(['manager_id' => $manager->id]);

        $this->actingAs($manager)->post('/recommendations', $this->payload($junior));

        $this->actingAs($manager)
            ->post('/recommendations', $this->payload($junior))
            ->assertSessionHasErrors('subject_user_id');

        $this->assertSame(1, TerminationRecommendation::query()->count());
    }

    public function test_the_desk_is_told_what_the_policy_says(): void
    {
        $hr = User::factory()->superAdmin()->create();
        $offence = Offence::factory()->withLadder()->create();

        $recommendation = TerminationRecommendation::factory()->create([
            'subject_user_id' => $this->staff()->id,
            'raised_by_id' => $this->staff()->id,
            'offence_id' => $offence->id,
            'occurrence' => 3,
        ]);

        // The seeded ladder reaches dismissal at the third occurrence.
        $this->assertTrue($recommendation->refresh()->policyAgrees());

        $this->actingAs($hr)
            ->get('/admin/recommendations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('recommendations.0.policy_says', 'Dismissal')
                ->where('recommendations.0.policy_agrees', true));
    }

    public function test_a_case_asking_for_more_than_the_handbook_says_is_flagged(): void
    {
        $hr = User::factory()->superAdmin()->create();
        $offence = Offence::factory()->withLadder()->create();

        TerminationRecommendation::factory()->create([
            'subject_user_id' => $this->staff()->id,
            'raised_by_id' => $this->staff()->id,
            'offence_id' => $offence->id,
            'occurrence' => 1,
        ]);

        $this->actingAs($hr)
            ->get('/admin/recommendations')
            ->assertInertia(fn ($page) => $page
                ->where('recommendations.0.policy_says', 'Verbal warning')
                ->where('recommendations.0.policy_agrees', false));
    }

    public function test_hr_can_accept_one_and_the_raiser_is_told(): void
    {
        Notification::fake();

        $hr = User::factory()->superAdmin()->create();
        $manager = $this->staff();
        $subject = $this->staff(['manager_id' => $manager->id]);

        $recommendation = TerminationRecommendation::factory()->create([
            'subject_user_id' => $subject->id,
            'raised_by_id' => $manager->id,
        ]);

        $this->actingAs($hr)
            ->put("/admin/recommendations/{$recommendation->id}", [
                'status' => RecommendationStatus::Accepted->value,
                'hr_note' => 'Agreed after a hearing on the 14th.',
            ])
            ->assertSessionHasNoErrors();

        $recommendation->refresh();

        $this->assertSame(RecommendationStatus::Accepted, $recommendation->status);
        $this->assertSame($hr->id, $recommendation->decided_by_id);
        Notification::assertSentTo($manager, RecommendationDecided::class);
    }

    public function test_accepting_does_not_touch_the_staff_record(): void
    {
        Notification::fake();

        $hr = User::factory()->superAdmin()->create();
        $subject = $this->staff();

        $recommendation = TerminationRecommendation::factory()->create([
            'subject_user_id' => $subject->id,
            'raised_by_id' => $this->staff()->id,
        ]);

        $this->actingAs($hr)->put("/admin/recommendations/{$recommendation->id}", [
            'status' => RecommendationStatus::Accepted->value,
            'hr_note' => 'Agreed after a hearing on the 14th.',
        ]);

        $subject->refresh();

        // Still on staff, still active: ending the employment is a separate
        // act on the staff page, with the clearing up that goes with it.
        $this->assertTrue($subject->is_active);
        $this->assertNull($subject->exit_date);
        $this->assertNull($subject->exit_reason);
    }

    public function test_a_note_is_required_either_way(): void
    {
        $hr = User::factory()->superAdmin()->create();

        $recommendation = TerminationRecommendation::factory()->create([
            'subject_user_id' => $this->staff()->id,
            'raised_by_id' => $this->staff()->id,
        ]);

        $this->actingAs($hr)
            ->put("/admin/recommendations/{$recommendation->id}", [
                'status' => RecommendationStatus::Declined->value,
                'hr_note' => '',
            ])
            ->assertSessionHasErrors('hr_note');
    }

    public function test_one_already_answered_is_not_answered_again(): void
    {
        Notification::fake();

        $hr = User::factory()->superAdmin()->create();

        $recommendation = TerminationRecommendation::factory()->create([
            'subject_user_id' => $this->staff()->id,
            'raised_by_id' => $this->staff()->id,
            'status' => RecommendationStatus::Declined,
        ]);

        $this->actingAs($hr)->put("/admin/recommendations/{$recommendation->id}", [
            'status' => RecommendationStatus::Accepted->value,
            'hr_note' => 'Changed my mind about this one entirely.',
        ]);

        $this->assertSame(RecommendationStatus::Declined, $recommendation->refresh()->status);
    }

    public function test_the_raiser_can_withdraw_one_hr_has_not_answered(): void
    {
        $manager = $this->staff();

        $recommendation = TerminationRecommendation::factory()->create([
            'subject_user_id' => $this->staff()->id,
            'raised_by_id' => $manager->id,
        ]);

        $this->actingAs($manager)->delete("/recommendations/{$recommendation->id}");

        $this->assertSame(RecommendationStatus::Withdrawn, $recommendation->refresh()->status);
    }

    public function test_staff_cannot_read_the_hr_desk(): void
    {
        $this->actingAs($this->staff())
            ->get('/admin/recommendations')
            ->assertForbidden();
    }
}
