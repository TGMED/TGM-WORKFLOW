<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Invitation;
use App\Support\WhatsNew;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * There is no sign-up. The people team adds somebody, and they get in through
 * the link they are emailed.
 */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create(['name' => 'TGM Ikeja']);
    }

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * Add somebody from the staff list, and hand back the token they were
     * emailed.
     */
    private function addSomebody(string $email = 'amara@tgm.test'): string
    {
        Notification::fake();

        $this->actingAs($this->admin())->post('/admin/staff', [
            'name' => 'Amara Nwosu',
            'email' => $email,
            'roles' => [Role::STAFF],
            'location_id' => $this->location->id,
        ])->assertSessionHasNoErrors();

        $token = null;

        Notification::assertSentTo(
            User::query()->where('email', $email)->firstOrFail(),
            Invitation::class,
            function (Invitation $invitation) use (&$token): bool {
                $token = $invitation->token;

                return true;
            },
        );

        auth()->logout();

        return (string) $token;
    }

    public function test_adding_somebody_emails_them_an_invitation_rather_than_asking_for_a_password(): void
    {
        $token = $this->addSomebody();

        $user = User::query()->where('email', 'amara@tgm.test')->firstOrFail();

        $this->assertSame('invited', $user->invitationState());
        // Only a hash is kept.
        $this->assertNotSame($token, $user->invitation_token);
        $this->assertSame(hash('sha256', $token), $user->invitation_token);
    }

    public function test_the_invitation_email_carries_the_link_and_how_long_it_lasts(): void
    {
        config(['hr.invitation_days' => 7]);

        $user = User::factory()->make(['name' => 'Amara Nwosu']);
        $mail = (new Invitation('some-token'))->toMail($user);
        $html = (string) $mail->render();
        $text = (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());

        $this->assertSame('Hello Amara,', $mail->greeting);
        $this->assertSame(route('invitation.show', 'some-token'), $mail->actionUrl);
        $this->assertStringContainsString('The link works for 7 days.', $html);

        // Nobody invited has an account yet, so there are no settings to offer.
        $this->assertStringNotContainsString(route('notifications.edit'), $html);
        $this->assertStringNotContainsString(route('notifications.edit'), $text);

        // The backup link reads as a plain address in the text part.
        $this->assertStringContainsString('paste this link into your browser: '.route('invitation.show', 'some-token'), $text);
        $this->assertStringNotContainsString('](', $text);
    }

    public function test_a_one_day_invitation_says_day_not_days(): void
    {
        config(['hr.invitation_days' => 1]);

        $mail = (new Invitation('some-token'))->toMail(User::factory()->make());

        $this->assertContains('The link works for 1 day. If it runs out, ask the people team to send another.', $mail->outroLines);
    }

    public function test_the_link_opens_a_page_to_choose_a_password(): void
    {
        $token = $this->addSomebody();

        $this->get("/invitation/{$token}")
            ->assertInertia(fn ($page) => $page
                ->component('auth/AcceptInvitation')
                ->where('valid', true)
                ->where('email', 'amara@tgm.test'));
    }

    public function test_choosing_a_password_signs_them_in_and_starts_the_setup(): void
    {
        $token = $this->addSomebody();

        $this->post("/invitation/{$token}", [
            'password' => 'Correct-Horse-Battery-9',
            'password_confirmation' => 'Correct-Horse-Battery-9',
        ])->assertRedirect('/dashboard');

        $user = User::query()->where('email', 'amara@tgm.test')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->invitationState());
        $this->assertNotNull($user->email_verified_at);
        // Release notes say what changed since somebody was last here, and a
        // new starter never was.
        $this->assertSame(WhatsNew::version(), $user->whats_new_seen);

        // A new starter has details to give, so the dashboard sends them on.
        $this->get('/dashboard')->assertRedirect('/profile/setup');
        $this->get('/profile/setup')->assertInertia(fn ($page) => $page
            ->component('ProfileSetup')
            ->where('is_complete', false));
    }

    public function test_the_password_has_to_meet_the_rules(): void
    {
        $token = $this->addSomebody();

        $this->post("/invitation/{$token}", [
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_a_link_works_once(): void
    {
        $token = $this->addSomebody();

        $this->post("/invitation/{$token}", [
            'password' => 'Correct-Horse-Battery-9',
            'password_confirmation' => 'Correct-Horse-Battery-9',
        ]);
        auth()->logout();

        $this->get("/invitation/{$token}")
            ->assertInertia(fn ($page) => $page->where('valid', false)->where('email', null));

        $this->post("/invitation/{$token}", [
            'password' => 'Another-Horse-Battery-9',
            'password_confirmation' => 'Another-Horse-Battery-9',
        ])->assertStatus(410);
    }

    public function test_a_link_runs_out(): void
    {
        $token = $this->addSomebody();

        Carbon::setTestNow(now()->addDays((int) config('hr.invitation_days') + 1));

        $this->assertSame(
            'expired',
            User::query()->where('email', 'amara@tgm.test')->firstOrFail()->invitationState(),
        );

        $this->get("/invitation/{$token}")->assertInertia(fn ($page) => $page->where('valid', false));
        $this->post("/invitation/{$token}", [
            'password' => 'Correct-Horse-Battery-9',
            'password_confirmation' => 'Correct-Horse-Battery-9',
        ])->assertStatus(410);
    }

    public function test_sending_it_again_retires_the_old_link(): void
    {
        $old = $this->addSomebody();
        $user = User::query()->where('email', 'amara@tgm.test')->firstOrFail();

        Notification::fake();

        $this->actingAs($this->admin())
            ->post("/admin/staff/{$user->id}/invitation")
            ->assertSessionHasNoErrors();

        $new = null;
        Notification::assertSentTo($user, Invitation::class, function (Invitation $invitation) use (&$new): bool {
            $new = $invitation->token;

            return true;
        });
        auth()->logout();

        $this->assertNotSame($old, $new);
        $this->get("/invitation/{$old}")->assertInertia(fn ($page) => $page->where('valid', false));
        $this->get("/invitation/{$new}")->assertInertia(fn ($page) => $page->where('valid', true));
    }

    public function test_nobody_is_invited_twice_once_they_are_in(): void
    {
        $staff = User::factory()->create(['location_id' => $this->location->id]);

        $this->actingAs($this->admin())
            ->post("/admin/staff/{$staff->id}/invitation")
            ->assertStatus(422);
    }

    public function test_a_deactivated_person_cannot_use_their_link(): void
    {
        $token = $this->addSomebody();

        User::query()->where('email', 'amara@tgm.test')->update(['is_active' => false]);

        $this->get("/invitation/{$token}")->assertInertia(fn ($page) => $page->where('valid', false));
    }

    public function test_the_staff_list_shows_who_has_not_got_in_yet(): void
    {
        $this->addSomebody();

        $this->actingAs($this->admin())
            ->get('/admin/staff?search=amara')
            ->assertInertia(fn ($page) => $page
                ->where('staff.data.0.email', 'amara@tgm.test')
                ->where('staff.data.0.invitation', 'invited')
                ->where('invitation_days', (int) config('hr.invitation_days')));
    }

    public function test_resetting_a_forgotten_password_spends_the_invitation(): void
    {
        $this->addSomebody();
        $user = User::query()->where('email', 'amara@tgm.test')->firstOrFail();

        $this->post('/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'Correct-Horse-Battery-9',
            'password_confirmation' => 'Correct-Horse-Battery-9',
        ])->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->invitationState());
    }

    public function test_the_token_never_reaches_the_audit_trail(): void
    {
        $token = $this->addSomebody();

        $this->assertFalse(
            Audit::query()->get()->contains(
                fn (Audit $audit): bool => str_contains(json_encode($audit->new_values) ?: '', hash('sha256', $token)),
            ),
        );
    }

    public function test_an_imported_starter_is_invited_once_the_import_commits(): void
    {
        $sent = [];
        // The real queue and mailer rather than a fake: a fake records the
        // send at once and would not show what the rollback throws away.
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent): void {
            $sent[] = array_map(fn ($address) => $address->getAddress(), $event->message->getTo());
        });

        $admin = $this->admin();

        // Checking the file writes nothing, so it must email nobody either.
        $this->actingAs($admin)->post('/admin/imports/staff', [
            'file' => $this->csv([['new@example.com', 'New Person', 'TGM Ikeja']]),
            'duplicates' => 'update',
            'commit' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame([], $sent);

        $this->actingAs($admin)->post('/admin/imports/staff', [
            'file' => $this->csv([['new@example.com', 'New Person', 'TGM Ikeja']]),
            'duplicates' => 'update',
            'commit' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame([['new@example.com']], $sent);
        $this->assertSame(
            'invited',
            User::query()->where('email', 'new@example.com')->firstOrFail()->invitationState(),
        );
    }

    public function test_an_import_does_not_invite_somebody_already_on_the_list(): void
    {
        User::factory()->create(['email' => 'already@example.com', 'location_id' => $this->location->id]);

        Notification::fake();

        $this->actingAs($this->admin())->post('/admin/imports/staff', [
            'file' => $this->csv([['already@example.com', 'Already Here', 'TGM Ikeja']]),
            'duplicates' => 'update',
            'commit' => true,
        ])->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_the_setup_is_not_offered_to_an_administrator(): void
    {
        $this->actingAs($this->admin())->get('/profile/setup')->assertRedirect('/dashboard');
    }

    public function test_a_finished_profile_can_still_open_the_later_steps(): void
    {
        $staff = User::factory()->create(['location_id' => $this->location->id]);

        $this->actingAs($staff)
            ->get('/profile/setup?step=bank')
            ->assertInertia(fn ($page) => $page->component('ProfileSetup')->where('is_complete', true));
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function csv(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
        $handle = fopen($path, 'w');

        fputcsv($handle, ['email', 'name', 'location']);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return new UploadedFile($path, 'import.csv', 'text/csv', null, true);
    }
}
