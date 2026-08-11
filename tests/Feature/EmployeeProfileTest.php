<?php

namespace Tests\Feature;

use App\Enums\RelationKind;
use App\Models\EmployeeRelation;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeProfileTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();
    }

    private function staff(): User
    {
        return User::factory()->create(['location_id' => $this->location->id]);
    }

    /**
     * The fields a complete Profile tab submission carries.
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'first_name' => 'Victor',
            'last_name' => 'Ugwu',
            'gender' => 'Male',
            'date_of_birth' => '1996-07-31',
            'country_of_origin' => 'NG',
            'state_of_origin' => 'Enugu State',
            'phone' => '+2349053003200',
            'hired_at' => '2024-02-01',
            ...$overrides,
        ];
    }

    /**
     * The one required detail that is not part of the Profile tab's own form.
     */
    private function giveAddress(User $user): void
    {
        $user->addresses()->create([
            'label' => 'Residential',
            'street' => '14 Awolowo Road',
            'city' => 'Ikoyi',
            'state' => 'Lagos State',
            'country' => 'NG',
        ]);
    }

    public function test_staff_can_reach_their_profile(): void
    {
        $this->actingAs($this->staff())->get('/profile')->assertOk();
    }

    public function test_saving_the_profile_splits_across_the_user_and_the_profile(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->put('/profile', $this->payload([
                'other_names' => 'Chinonso',
                'employee_id' => 'TGM/VU/251013',
                'genotype' => 'AS',
                'local_government' => 'Udi',
                'alternate_email' => 'spare@example.com',
            ]))
            ->assertRedirect();

        $staff->refresh();

        // The staff ID, phone, joining date and display name belong to the
        // user record.
        $this->assertSame('TGM/VU/251013', $staff->employee_id);
        $this->assertSame('+2349053003200', $staff->phone);
        $this->assertSame('2024-02-01', $staff->hired_at->toDateString());
        $this->assertSame('Victor Chinonso Ugwu', $staff->name);

        // Everything else belongs to the profile.
        $this->assertSame('Enugu State', $staff->profile->state_of_origin);
        $this->assertSame('AS', $staff->profile->genotype);
        $this->assertSame('spare@example.com', $staff->profile->alternate_email);
        $this->assertNotNull($staff->profile->completed_at);
    }

    public function test_the_sign_in_email_cannot_be_changed_here(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->put('/profile', $this->payload(['email' => 'someone.else@example.com']))
            ->assertRedirect();

        $this->assertNotSame('someone.else@example.com', $staff->refresh()->email);
    }

    public function test_the_staff_id_is_optional_but_has_to_be_unique(): void
    {
        $taken = $this->staff();
        $taken->update(['employee_id' => 'TGM/001']);

        $staff = $this->staff();

        $this->actingAs($staff)
            ->put('/profile', $this->payload(['employee_id' => null]))
            ->assertSessionHasNoErrors();

        $this->actingAs($staff)
            ->put('/profile', $this->payload(['employee_id' => 'TGM/001']))
            ->assertSessionHasErrors('employee_id');
    }

    public function test_the_required_fields_are_enforced(): void
    {
        $this->actingAs($this->staff())
            ->put('/profile', [
                'first_name' => 'Victor',
                'place_of_birth' => 'Lagos',
            ])
            ->assertSessionHasErrors([
                'last_name', 'gender', 'date_of_birth',
                'country_of_origin', 'state_of_origin', 'phone', 'hired_at',
            ]);
    }

    public function test_a_pick_list_only_takes_values_from_its_list(): void
    {
        $this->actingAs($this->staff())
            ->put('/profile', $this->payload([
                'gender' => 'Martian',
                'country_of_origin' => 'ZZ',
                'genotype' => 'ZZ',
            ]))
            ->assertSessionHasErrors(['gender', 'country_of_origin', 'genotype']);
    }

    /**
     * The gate.
     */
    public function test_an_unfinished_profile_is_sent_back_to_the_profile_page(): void
    {
        $staff = User::factory()->withoutProfile()->create([
            'location_id' => $this->location->id,
        ]);

        $this->actingAs($staff)->get('/dashboard')->assertRedirect('/profile');
        $this->actingAs($staff)->get('/leave')->assertRedirect('/profile');
        $this->actingAs($staff)->get('/attendance')->assertRedirect('/profile');
    }

    /**
     * The whole point of the gate: someone signing in with an unfinished
     * record ends up on the profile page rather than the dashboard.
     */
    public function test_signing_in_with_an_unfinished_profile_lands_on_the_profile(): void
    {
        $staff = User::factory()->withoutProfile()->create([
            'location_id' => $this->location->id,
            'email' => 'victor@example.com',
        ]);

        $this->post('/login', [
            'email' => 'victor@example.com',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        // The hop the browser makes next is where the gate turns them around.
        $this->get('/dashboard')->assertRedirect('/profile');
        $this->get('/profile')->assertOk();

        $this->assertAuthenticatedAs($staff);
    }

    public function test_signing_in_with_a_finished_profile_goes_straight_through(): void
    {
        User::factory()->create([
            'location_id' => $this->location->id,
            'email' => 'done@example.com',
        ]);

        $this->post('/login', [
            'email' => 'done@example.com',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertOk();
    }

    public function test_an_unfinished_profile_cannot_write_anywhere_else(): void
    {
        $staff = User::factory()->incompleteProfile()->create([
            'location_id' => $this->location->id,
        ]);

        $this->actingAs($staff)->post('/clock/in')->assertForbidden();
    }

    public function test_the_profile_and_password_pages_stay_reachable_while_it_is_unfinished(): void
    {
        $staff = User::factory()->withoutProfile()->create([
            'location_id' => $this->location->id,
        ]);

        $this->actingAs($staff)->get('/profile')->assertOk();
        $this->actingAs($staff)->get('/settings/password')->assertOk();
        $this->actingAs($staff)->post('/logout')->assertRedirect('/login');
    }

    public function test_finishing_the_profile_opens_the_rest_of_the_app(): void
    {
        $staff = User::factory()->withoutProfile()->create([
            'location_id' => $this->location->id,
        ]);

        $this->actingAs($staff)->get('/dashboard')->assertRedirect('/profile');

        $this->actingAs($staff)->put('/profile', $this->payload());

        // The form alone is not the whole record: an address is saved from
        // its own card, and the gate holds them until it is there.
        $this->actingAs($staff->refresh())->get('/dashboard')->assertRedirect('/profile');

        $this->giveAddress($staff);

        $this->actingAs($staff->refresh())->get('/dashboard')->assertOk();
    }

    public function test_the_joining_date_is_required_and_cannot_be_far_ahead(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->put('/profile', $this->payload(['hired_at' => null]))
            ->assertSessionHasErrors('hired_at');

        $this->actingAs($staff)
            ->put('/profile', $this->payload([
                'hired_at' => now()->addYear()->toDateString(),
            ]))
            ->assertSessionHasErrors('hired_at');
    }

    public function test_a_missing_joining_date_alone_holds_someone_back(): void
    {
        $staff = User::factory()->create([
            'location_id' => $this->location->id,
            'hired_at' => null,
        ]);

        $this->actingAs($staff)->get('/dashboard')->assertRedirect('/profile');
    }

    public function test_an_address_is_required_before_the_app_opens_up(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->get('/dashboard')->assertOk();

        $staff->addresses()->delete();

        $this->actingAs($staff->refresh())->get('/dashboard')->assertRedirect('/profile');
    }

    /**
     * Adding the first address can be the moment the record is finished, and
     * that moment is worth stamping wherever it happens.
     */
    public function test_adding_the_first_address_finishes_the_record(): void
    {
        $staff = User::factory()->withoutProfile()->create([
            'location_id' => $this->location->id,
        ]);

        $this->actingAs($staff)->put('/profile', $this->payload());

        $this->assertNull($staff->refresh()->profile->completed_at);

        $this->actingAs($staff)->post('/profile/addresses', [
            'label' => 'Residential',
            'street' => '14 Awolowo Road',
            'country' => 'NG',
        ])->assertRedirect();

        $this->assertNotNull($staff->refresh()->profile->completed_at);
    }

    public function test_the_last_address_cannot_be_removed(): void
    {
        $staff = $this->staff();
        $address = $staff->addresses()->firstOrFail();

        $this->actingAs($staff)
            ->delete("/profile/addresses/{$address->id}")
            ->assertRedirect();

        $this->assertModelExists($address);

        $this->giveAddress($staff);

        $this->actingAs($staff)->delete("/profile/addresses/{$address->id}");

        $this->assertModelMissing($address);
    }

    public function test_a_missing_phone_number_alone_holds_someone_back(): void
    {
        $staff = User::factory()->create([
            'location_id' => $this->location->id,
            'phone' => null,
        ]);

        $this->actingAs($staff)->get('/dashboard')->assertRedirect('/profile');
    }

    /**
     * Admins run the system rather than appear on the payroll, so they have no
     * HR record to keep and nothing to be held back for.
     */
    public function test_super_admins_are_not_gated(): void
    {
        $admin = User::factory()->superAdmin()->withoutProfile()->create();

        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $this->actingAs($admin)->get('/admin/staff')->assertOk();
    }

    /**
     * The Bank Account tab.
     */
    public function test_bank_details_save_and_stay_optional(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->put('/profile/bank', [])->assertSessionHasNoErrors();

        $this->actingAs($staff)
            ->put('/profile/bank', [
                'bank_name' => 'Zenith Bank',
                'account_number' => '1234567890',
                'account_name' => 'Victor Ugwu',
                'bvn' => '12345678901',
                'rsa_number' => 'PEN100200300',
                'nhf_number' => 'NHF/44',
            ])
            ->assertSessionHasNoErrors();

        $profile = $staff->refresh()->profile;

        $this->assertSame('Zenith Bank', $profile->bank_name);
        $this->assertSame('1234567890', $profile->account_number);
        $this->assertSame('NHF/44', $profile->nhf_number);
    }

    public function test_a_malformed_account_number_or_bvn_is_rejected(): void
    {
        $this->actingAs($this->staff())
            ->put('/profile/bank', [
                'account_number' => '12345',
                'bvn' => '123',
                'bank_name' => 'Bank of Nowhere',
            ])
            ->assertSessionHasErrors(['account_number', 'bvn', 'bank_name']);
    }

    /**
     * The Family tab.
     */
    public function test_family_members_can_be_added_changed_and_removed(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post('/profile/relations', [
            'kind' => 'next_of_kin',
            'name' => 'Ugwu Angela',
            'relationship' => 'Mother',
            'phone' => '+2348012345678',
        ])->assertSessionHasNoErrors();

        $relation = $staff->relations()->sole();
        $this->assertSame(RelationKind::NextOfKin, $relation->kind);

        $this->actingAs($staff)->put("/profile/relations/{$relation->id}", [
            'kind' => 'next_of_kin',
            'name' => 'Ugwu Angela',
            'relationship' => 'Sister',
            'phone' => '+2348012345678',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Sister', $relation->refresh()->relationship);

        $this->actingAs($staff)->delete("/profile/relations/{$relation->id}");

        $this->assertSame(0, $staff->relations()->count());
    }

    public function test_a_next_of_kin_needs_a_phone_number_but_a_dependant_does_not(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post('/profile/relations', [
            'kind' => 'next_of_kin',
            'name' => 'Ugwu Angela',
            'relationship' => 'Mother',
        ])->assertSessionHasErrors('phone');

        $this->actingAs($staff)->post('/profile/relations', [
            'kind' => 'dependant',
            'name' => 'Ugwu Junior',
            'relationship' => 'Son',
        ])->assertSessionHasNoErrors();
    }

    public function test_nobody_can_touch_someone_elses_family_record(): void
    {
        $mine = $this->staff();
        $theirs = $this->staff();

        $relation = EmployeeRelation::factory()->create([
            'user_id' => $theirs->id,
            'kind' => RelationKind::NextOfKin,
        ]);

        $this->actingAs($mine)
            ->put("/profile/relations/{$relation->id}", [
                'kind' => 'next_of_kin',
                'name' => 'Impostor',
                'relationship' => 'Father',
                'phone' => '+2348012345678',
            ])
            ->assertForbidden();

        $this->actingAs($mine)
            ->delete("/profile/relations/{$relation->id}")
            ->assertForbidden();

        $this->assertNotSame('Impostor', $relation->refresh()->name);
    }

    /**
     * Addresses.
     */
    public function test_addresses_can_be_kept_and_belong_to_their_owner(): void
    {
        $mine = $this->staff();
        $theirs = $this->staff();

        $this->actingAs($mine)->post('/profile/addresses', [
            'label' => 'Residential',
            'street' => '12 Ademola Street',
            'city' => 'Lagos',
            'state' => 'Lagos State',
            'country' => 'NG',
        ])->assertSessionHasNoErrors();

        // Everyone starts with one on file, so pick out the one just added.
        $address = $mine->addresses()->where('street', '12 Ademola Street')->sole();

        $this->assertStringContainsString('Nigeria', $address->oneLine());

        $this->actingAs($theirs)
            ->delete("/profile/addresses/{$address->id}")
            ->assertForbidden();
    }

    /**
     * Photos.
     */
    public function test_a_photo_replaces_the_one_before_it(): void
    {
        Storage::fake('public');

        $staff = $this->staff();

        $this->actingAs($staff)
            ->post('/profile/photo', ['photo' => UploadedFile::fake()->image('me.jpg')])
            ->assertSessionHasNoErrors();

        $first = $staff->refresh()->profile->avatar_path;
        Storage::disk('public')->assertExists($first);

        $this->actingAs($staff)
            ->post('/profile/photo', ['photo' => UploadedFile::fake()->image('newer.png')]);

        $second = $staff->refresh()->profile->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_a_file_that_is_not_an_image_is_turned_away(): void
    {
        Storage::fake('public');

        $this->actingAs($this->staff())
            ->post('/profile/photo', ['photo' => UploadedFile::fake()->create('payroll.pdf', 20)])
            ->assertSessionHasErrors('photo');
    }

    /**
     * Completeness.
     */
    public function test_completed_at_records_when_and_is_not_moved_by_later_edits(): void
    {
        $staff = User::factory()->withoutProfile()->create([
            'location_id' => $this->location->id,
        ]);

        $this->giveAddress($staff);
        $this->actingAs($staff)->put('/profile', $this->payload());

        $stamped = $staff->refresh()->profile->completed_at;
        $this->assertNotNull($stamped);

        $this->travel(1)->days();

        $this->actingAs($staff)->put('/profile', $this->payload(['place_of_birth' => 'Lagos']));

        $this->assertTrue($stamped->equalTo($staff->refresh()->profile->completed_at));
    }

    public function test_the_page_names_what_is_still_missing(): void
    {
        $staff = User::factory()->incompleteProfile()->create([
            'location_id' => $this->location->id,
        ]);

        $this->actingAs($staff)
            ->get('/profile')
            ->assertInertia(fn ($page) => $page
                ->where('is_complete', false)
                ->where('missing_fields', fn (Collection $missing): bool => $missing->contains('gender')
                    && $missing->contains('country_of_origin'))
            );
    }

    public function test_a_profile_with_every_required_field_reads_as_complete(): void
    {
        $user = $this->staff();

        $this->assertTrue($user->hasCompleteProfile());
        $this->assertSame([], $user->profileRecord()->missingFields());
    }
}
