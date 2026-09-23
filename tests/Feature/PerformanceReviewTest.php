<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\ReviewStanding;
use App\Enums\ReviewVisibility;
use App\Models\Department;
use App\Models\Location;
use App\Models\PerformanceReview;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Staff reviewing each other's work, and who gets to read it.
 */
class PerformanceReviewTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private Department $department;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();
        $this->department = Department::factory()->create();
        $this->team = Team::factory()->create(['department_id' => $this->department->id]);
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->create([
            'location_id' => $this->location->id,
            ...$attributes,
        ]);
    }

    private function teamMember(): User
    {
        return $this->staff([
            'department_id' => $this->department->id,
            'team_id' => $this->team->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $subject, array $overrides = []): array
    {
        return [
            'subject_user_id' => $subject->id,
            'visibility' => 'public',
            'rating' => 4,
            'body' => 'Took the handover from the night shift without a single gap this month.',
            ...$overrides,
        ];
    }

    public function test_the_team_lead_writes_as_lead(): void
    {
        $lead = $this->teamMember();
        $this->team->update(['lead_user_id' => $lead->id]);
        $member = $this->teamMember();

        $this->actingAs($lead->refresh())
            ->post('/reviews', $this->payload($member))
            ->assertSessionHasNoErrors();

        $review = PerformanceReview::query()->sole();

        $this->assertSame(ReviewStanding::Lead, $review->standing);
        $this->assertSame(ReviewVisibility::Public, $review->visibility);
        $this->assertSame($lead->id, $review->reviewer_id);
    }

    public function test_the_head_of_department_and_the_line_manager_write_as_management(): void
    {
        $head = $this->staff(['department_id' => $this->department->id]);
        $this->department->update(['head_user_id' => $head->id]);
        $manager = $this->staff();
        $member = $this->teamMember();
        $member->update(['manager_id' => $manager->id]);

        $this->assertSame(ReviewStanding::Management, PerformanceReview::standingOf($head->refresh(), $member));
        $this->assertSame(ReviewStanding::Management, PerformanceReview::standingOf($manager, $member->refresh()));
    }

    public function test_a_teammate_writes_as_a_colleague(): void
    {
        $this->assertSame(
            ReviewStanding::Peer,
            PerformanceReview::standingOf($this->teamMember(), $this->teamMember()),
        );
    }

    public function test_somebody_outside_the_group_is_heard_by_hr_alone(): void
    {
        $stranger = $this->staff();
        $member = $this->teamMember();

        $this->actingAs($stranger)
            ->post('/reviews', $this->payload($member, ['visibility' => 'public']))
            ->assertSessionHasNoErrors();

        $review = PerformanceReview::query()->sole();

        $this->assertSame(ReviewStanding::Outside, $review->standing);
        $this->assertSame(ReviewVisibility::Private, $review->visibility);
    }

    public function test_the_subject_reads_public_reviews_without_the_author(): void
    {
        $member = $this->teamMember();
        $author = $this->teamMember();

        PerformanceReview::factory()->create([
            'subject_user_id' => $member->id,
            'reviewer_id' => $author->id,
            'body' => 'Shared and kind.',
        ]);
        PerformanceReview::factory()->private()->create([
            'subject_user_id' => $member->id,
            'reviewer_id' => $author->id,
            'body' => 'For HR only.',
        ]);

        // Nothing on the row beyond what was said and when: not the author,
        // and not their standing, which would name a team lead just as well.
        $this->actingAs($member)
            ->get('/reviews')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reviews')
                ->has('about_me', 1)
                ->has('about_me.0', fn (Assert $row) => $row
                    ->where('body', 'Shared and kind.')
                    ->hasAll(['id', 'rating', 'created_at'])));
    }

    public function test_nobody_can_review_themselves(): void
    {
        $member = $this->teamMember();

        $this->actingAs($member)
            ->post('/reviews', $this->payload($member))
            ->assertSessionHasErrors('subject_user_id');

        $this->assertSame(0, PerformanceReview::query()->count());
    }

    public function test_an_administrator_cannot_be_reviewed(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($this->teamMember())
            ->post('/reviews', $this->payload($admin))
            ->assertSessionHasErrors('subject_user_id');
    }

    public function test_the_admin_list_needs_its_own_permission(): void
    {
        $role = Role::query()->create(['slug' => 'people_team', 'name' => 'People team', 'is_system' => false]);
        $role->syncPermissions([Permission::ManageStaff]);
        $hr = User::factory()->roles($role->slug)->create(['location_id' => $this->location->id]);

        $this->actingAs($hr)->get('/admin/reviews')->assertForbidden();

        $role->syncPermissions([Permission::ManageStaff, Permission::ViewPerformanceReviews]);

        $this->actingAs($hr->refresh())->get('/admin/reviews')->assertOk();
    }

    public function test_the_admin_list_names_the_author_of_private_reviews(): void
    {
        $author = $this->staff(['name' => 'Ada Obi']);

        PerformanceReview::factory()->private()->create([
            'subject_user_id' => $this->teamMember()->id,
            'reviewer_id' => $author->id,
            'standing' => ReviewStanding::Outside,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/admin/reviews?visibility=private')
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Reviews')
                ->has('reviews.data', 1)
                ->where('reviews.data.0.reviewer', 'Ada Obi')
                ->where('counts.private', 1));
    }
}
