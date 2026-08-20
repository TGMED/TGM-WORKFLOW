<?php

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTopic;
use App\Models\Location;
use App\Models\NotificationSetting;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create([
            'location_id' => Location::factory()->create()->id,
        ]);
    }

    /**
     * @param  array<string, bool>  $overrides
     * @return array<int, array<string, mixed>>
     */
    private function answers(array $overrides = []): array
    {
        return array_map(fn (NotificationTopic $topic): array => [
            'topic' => $topic->value,
            'email' => $overrides["{$topic->value}.email"] ?? true,
            'push' => $overrides["{$topic->value}.push"] ?? true,
        ], NotificationTopic::cases());
    }

    public function test_everything_is_on_before_anyone_touches_it(): void
    {
        $staff = $this->staff();

        foreach (NotificationTopic::cases() as $topic) {
            $this->assertTrue(
                NotificationSetting::allows($staff, $topic, NotificationChannel::Email),
                "{$topic->value} should start switched on",
            );
        }
    }

    public function test_someone_can_switch_a_topic_off(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->put('/settings/notifications', [
                'topics' => $this->answers([
                    'announcement.email' => false,
                    'announcement.push' => false,
                ]),
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse(NotificationSetting::allows(
            $staff->fresh(),
            NotificationTopic::Announcement,
            NotificationChannel::Email,
        ));

        // The other topics are untouched.
        $this->assertTrue(NotificationSetting::allows(
            $staff->fresh(),
            NotificationTopic::Birthday,
            NotificationChannel::Email,
        ));
    }

    public function test_the_two_channels_are_switched_separately(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->put('/settings/notifications', [
            'topics' => $this->answers(['birthday.push' => false]),
        ]);

        $this->assertTrue(NotificationSetting::allows(
            $staff->fresh(),
            NotificationTopic::Birthday,
            NotificationChannel::Email,
        ));
        $this->assertFalse(NotificationSetting::allows(
            $staff->fresh(),
            NotificationTopic::Birthday,
            NotificationChannel::Push,
        ));
    }

    public function test_approvals_cannot_be_switched_off(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->put('/settings/notifications', [
            'topics' => $this->answers([
                'approval_requested.email' => false,
                'approval_requested.push' => false,
            ]),
        ]);

        // Somebody's time off is held up by these, so they are not optional.
        $this->assertTrue(NotificationSetting::allows(
            $staff->fresh(),
            NotificationTopic::ApprovalRequested,
            NotificationChannel::Email,
        ));
    }

    public function test_the_settings_page_lists_every_topic(): void
    {
        $this->actingAs($this->staff())
            ->get('/settings/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Notifications')
                ->has('topics', count(NotificationTopic::cases()))
                ->where('push.configured', false));
    }

    public function test_a_browser_can_be_registered_for_push_and_dropped_again(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->post('/push-tokens', ['token' => 'firebase-token-one'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PushToken::query()->where('user_id', $staff->id)->count());

        // Registering the same browser again is not a second browser.
        $this->actingAs($staff)->post('/push-tokens', ['token' => 'firebase-token-one']);
        $this->assertSame(1, PushToken::query()->where('user_id', $staff->id)->count());

        $this->actingAs($staff)->delete('/push-tokens');

        $this->assertSame(0, PushToken::query()->where('user_id', $staff->id)->count());
    }

    public function test_a_shared_browser_follows_whoever_registered_it_last(): void
    {
        $first = $this->staff();
        $second = $this->staff();

        $this->actingAs($first)->post('/push-tokens', ['token' => 'shared-machine']);
        $this->actingAs($second)->post('/push-tokens', ['token' => 'shared-machine']);

        $this->assertSame(0, PushToken::query()->where('user_id', $first->id)->count());
        $this->assertSame(1, PushToken::query()->where('user_id', $second->id)->count());
    }

    public function test_a_signed_out_visitor_cannot_register_a_browser(): void
    {
        $this->post('/push-tokens', ['token' => 'nobody'])->assertRedirect('/login');
    }
}
