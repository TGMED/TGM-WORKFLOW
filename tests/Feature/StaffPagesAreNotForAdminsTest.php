<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A super admin runs the company's records rather than appearing in them: no
 * salary, no HR record, no notices addressed to them. The staff side of the
 * app is not theirs, and each of these pages has an admin counterpart that
 * is.
 */
class StaffPagesAreNotForAdminsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create([
            'location_id' => Location::factory()->create()->id,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function staffPages(): array
    {
        return [
            'the noticeboard' => ['/announcements'],
            'the org chart' => ['/organogram'],
            'the handbook' => ['/policies'],
            'their own payslips' => ['/payslips'],
            'their own HR record' => ['/profile'],
        ];
    }

    #[DataProvider('staffPages')]
    public function test_a_super_admin_is_sent_back_to_the_dashboard(string $page): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get($page)
            ->assertRedirect('/dashboard');
    }

    #[DataProvider('staffPages')]
    public function test_staff_still_reach_it(string $page): void
    {
        $this->actingAs($this->staff())
            ->get($page)
            ->assertSuccessful();
    }

    public function test_a_super_admin_cannot_write_to_their_own_record(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->put('/profile', ['name' => 'Someone Else'])
            ->assertForbidden();
    }

    /**
     * The two the company decided an admin keeps: anybody may raise a
     * concern, and knowing who is out is not a staff privilege.
     */
    public function test_a_super_admin_keeps_incidents_and_the_away_board(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->get('/reports')->assertSuccessful();
        $this->actingAs($admin)->get('/away')->assertSuccessful();
    }

    public function test_the_admin_noticeboard_is_the_one_they_get(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/admin/announcements')
            ->assertInertia(fn ($page) => $page->component('admin/Announcements'));
    }
}
