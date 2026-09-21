<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The password forms mirror these rules as a live checklist, so they have to
 * hold outside production too. Checked through changing a password, which
 * every account can do.
 */
class PasswordRulesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function passwordProvider(): array
    {
        return [
            'eight chars is enough' => ['Ab3!wxyz', true],
            'seven chars is not' => ['Ab3!wxy', false],
            'needs a number' => ['Abcd!wxyz', false],
            'needs a symbol' => ['Abcd3wxyz', false],
            'needs mixed case' => ['abcd3!wxyz', false],
        ];
    }

    #[DataProvider('passwordProvider')]
    public function test_a_new_password_has_to_meet_the_rules(string $password, bool $accepted): void
    {
        $user = User::factory()->create([
            'location_id' => Location::factory()->create()->id,
        ]);

        $response = $this->actingAs($user)->put('/settings/password', [
            'current_password' => 'password',
            'password' => $password,
            'password_confirmation' => $password,
        ]);

        $accepted
            ? $response->assertSessionHasNoErrors()
            : $response->assertSessionHasErrors('password');
    }
}
