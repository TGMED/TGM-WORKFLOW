<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Request a reset link and hand back the token that was mailed out.
     */
    private function requestResetToken(User $user): string
    {
        $this->post('/forgot-password', ['email' => $user->email]);

        $token = null;

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );

        return $token;
    }

    public function test_forgot_password_screen_renders(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    public function test_a_reset_link_is_sent(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_an_unknown_email_reports_success_but_sends_nothing(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nobody@tgm.test'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_a_deactivated_user_can_still_reset_their_password(): void
    {
        Notification::fake();

        $user = User::factory()->deactivated()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_reset_screen_renders_with_a_token(): void
    {
        Notification::fake();

        $token = $this->requestResetToken(User::factory()->create());

        $this->get('/reset-password/'.$token)->assertOk();
    }

    public function test_a_password_can_be_reset_with_a_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $token = $this->requestResetToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'New-Password123!',
            'password_confirmation' => 'New-Password123!',
        ])->assertRedirect('/login')->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('New-Password123!', $user->fresh()->password));
    }

    public function test_a_token_cannot_be_used_twice(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $token = $this->requestResetToken($user);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'New-Password123!',
            'password_confirmation' => 'New-Password123!',
        ];

        $this->post('/reset-password', $payload)->assertSessionHasNoErrors();
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'New-Password123!',
            'password_confirmation' => 'New-Password123!',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_a_weak_password_is_rejected(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $token = $this->requestResetToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');
    }

    public function test_resets_are_throttled_per_email(): void
    {
        $user = User::factory()->create();

        $payload = [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'New-Password123!',
            'password_confirmation' => 'New-Password123!',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->post('/reset-password', $payload)->assertStatus(302);
        }

        $this->post('/reset-password', $payload)->assertStatus(429);
    }

    public function test_the_email_bucket_does_not_block_a_colleague_on_the_same_ip(): void
    {
        $user = User::factory()->create();
        $colleague = User::factory()->create();

        $payload = [
            'token' => 'not-a-real-token',
            'password' => 'New-Password123!',
            'password_confirmation' => 'New-Password123!',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->post('/reset-password', [...$payload, 'email' => $user->email])
                ->assertStatus(302);
        }

        // Same IP, different account: the looser IP bucket still has room.
        $this->post('/reset-password', [...$payload, 'email' => $colleague->email])
            ->assertStatus(302);
    }

    public function test_a_mismatched_confirmation_is_rejected(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $token = $this->requestResetToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'New-Password123!',
            'password_confirmation' => 'Different-Password123!',
        ])->assertSessionHasErrors('password');
    }
}
