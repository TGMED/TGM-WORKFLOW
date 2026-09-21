<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use App\Support\WhatsNew;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Errors land on the app's own page, in words the reader can act on, and a
 * new starter is never shown one for putting the release notes away.
 */
class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create([
            'location_id' => Location::factory()->create()->id,
        ]);
    }

    public function test_a_forbidden_page_shows_the_error_page_with_the_apps_own_words(): void
    {
        $unfinished = User::factory()->withoutProfile()->create([
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->actingAs($unfinished)
            ->post('/out-of-office', [])
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page
                ->component('Error')
                ->where('status', 403)
                ->where('message', 'Finish your profile before using the rest of the app.'));
    }

    public function test_a_page_that_does_not_exist_says_so_in_plain_words(): void
    {
        $this->actingAs($this->staff())
            ->get('/no-such-page')
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page
                ->component('Error')
                ->where('status', 404)
                ->where('title', 'Page not found'));
    }

    public function test_a_missing_record_does_not_leak_the_model_behind_it(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/admin/staff/999999')
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page
                ->component('Error')
                ->where('message', fn (string $message): bool => ! str_contains($message, 'App\\Models')));
    }

    public function test_the_frameworks_stock_wording_is_replaced(): void
    {
        // The permission middleware refuses with the framework's own words.
        $this->actingAs($this->staff())
            ->get('/admin/roles')
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page
                ->component('Error')
                ->where('title', 'You cannot open this')
                ->where('message', fn (string $message): bool => $message !== 'This action is unauthorized.'));
    }

    public function test_a_server_error_gets_the_page_once_debugging_is_off(): void
    {
        Route::middleware('web')->get('/_boom', fn () => throw new RuntimeException('secret detail'));
        config(['app.debug' => false]);

        $this->get('/_boom')
            ->assertStatus(500)
            ->assertInertia(fn ($page) => $page
                ->component('Error')
                ->where('title', 'Something went wrong on our side')
                ->where('message', fn (string $message): bool => ! str_contains($message, 'secret detail')));
    }

    public function test_a_server_error_keeps_its_stack_trace_while_debugging(): void
    {
        Route::middleware('web')->get('/_boom', fn () => throw new RuntimeException('secret detail'));
        config(['app.debug' => true]);

        $response = $this->get('/_boom')->assertStatus(500);

        $this->assertStringNotContainsString('"component":"Error"', (string) $response->getContent());
    }

    public function test_an_expired_form_goes_back_to_the_form(): void
    {
        Route::middleware('web')->post('/_expired', fn () => throw new TokenMismatchException);

        $this->from('/leave')
            ->post('/_expired')
            ->assertRedirect('/leave')
            ->assertSessionHas('toast.type', 'error');
    }

    public function test_a_json_caller_still_gets_json(): void
    {
        $this->actingAs($this->staff())
            ->getJson('/admin/roles')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    public function test_a_new_starter_can_put_the_release_notes_away_before_finishing_their_profile(): void
    {
        $unfinished = User::factory()->withoutProfile()->create([
            'location_id' => Location::factory()->create()->id,
        ]);

        $this->actingAs($unfinished)
            ->post('/whats-new/seen')
            ->assertRedirect();

        $this->assertSame(WhatsNew::version(), $unfinished->fresh()->whats_new_seen);

        $this->actingAs($unfinished)
            ->post('/tours', ['tour' => 'dashboard'])
            ->assertRedirect();
    }
}
