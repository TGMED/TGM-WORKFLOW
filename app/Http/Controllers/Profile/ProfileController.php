<?php

namespace App\Http\Controllers\Profile;

use App\Enums\RelationKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateEmployeeProfileRequest;
use App\Models\EmployeeAddress;
use App\Models\EmployeeProfile;
use App\Models\EmployeeRelation;
use App\Models\User;
use App\Services\Paystack\PaystackClient;
use App\Support\Countries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(protected PaystackClient $paystack) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('Profile', $this->pageProps($request->user()));
    }

    /**
     * The same record, walked through a step at a time for somebody who has
     * just got in: the part the app cannot run without first, then the parts
     * that can wait. Where the profile gate sends anybody with details still
     * to give.
     */
    public function setup(Request $request): Response
    {
        return Inertia::render('ProfileSetup', $this->pageProps($request->user()));
    }

    /**
     * @return array<string, mixed>
     */
    protected function pageProps(User $user): array
    {
        $user->load(['profile', 'relations', 'addresses']);

        $profile = $user->profileRecord();

        return [
            'profile' => $this->payload($user, $profile),
            'relations' => $this->relations($user),
            'addresses' => $user->addresses
                ->map(fn (EmployeeAddress $address): array => [
                    'id' => $address->id,
                    'label' => $address->label,
                    'street' => $address->street,
                    'city' => $address->city,
                    'state' => $address->state,
                    'country' => $address->country,
                    'postal_code' => $address->postal_code,
                    'one_line' => $address->oneLine(),
                ])
                ->values(),
            'options' => $this->options(),
            'missing_fields' => $profile->missingFields(),
            'is_complete' => $profile->isComplete(),
        ];
    }

    /**
     * Save the Profile tab. The staff ID and primary phone belong to the user
     * record, everything else to the profile, so the write is split across
     * both and the display name is rebuilt from the parts given.
     */
    public function update(UpdateEmployeeProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        // Read this before anything is written, so we can tell a record being
        // finished for the first time from one merely being edited.
        $wasComplete = $user->hasCompleteProfile();

        $profile = $user->profile()->firstOrNew();
        $profile->setRelation('user', $user);
        $profile->fill(collect($validated)->except(['employee_id', 'phone'])->all());

        $user->fill([
            'employee_id' => $validated['employee_id'] ?? null,
            'phone' => $validated['phone'],
            // The display name the rest of the app shows is derived here, so
            // the sidebar and the avatar follow whatever they typed above.
            'name' => $profile->displayName() ?? $user->name,
        ])->save();

        // Stamp the first time the record was finished and leave the stamp
        // alone afterwards, so it records when, not merely that.
        if ($profile->completed_at === null && $profile->isComplete()) {
            $profile->completed_at = Carbon::now();
        }

        $user->profile()->save($profile);

        return $this->done(
            ! $wasComplete && $profile->isComplete()
                ? 'Your profile is complete. Welcome in.'
                : 'Your profile has been saved.',
        );
    }

    /**
     * Back to the tab they were on, so saving one card does not throw them
     * to the top of a six-card page.
     */
    protected function done(string $message): RedirectResponse
    {
        return back()->with('toast', ['type' => 'success', 'message' => $message]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(User $user, EmployeeProfile $profile): array
    {
        return [
            'employee_id' => $user->employee_id,
            'attendance_id' => $profile->attendance_id,
            'first_name' => $profile->first_name,
            'last_name' => $profile->last_name,
            'other_names' => $profile->other_names,
            'title' => $profile->title,
            'gender' => $profile->gender,
            'date_of_birth' => $profile->date_of_birth?->toDateString(),
            'place_of_birth' => $profile->place_of_birth,
            'marital_status' => $profile->marital_status,
            'mothers_maiden_name' => $profile->mothers_maiden_name,
            'spouse_name' => $profile->spouse_name,
            'spouse_phone' => $profile->spouse_phone,
            'number_of_kids' => $profile->number_of_kids,
            'blood_group' => $profile->blood_group,
            'genotype' => $profile->genotype,
            'religion' => $profile->religion,
            'allergies' => $profile->allergies,
            'medical_history' => $profile->medical_history,
            'national_id_number' => $profile->national_id_number,
            'country_of_origin' => $profile->country_of_origin,
            'state_of_origin' => $profile->state_of_origin,
            'local_government' => $profile->local_government,
            'phone' => $user->phone,
            'alternate_phone' => $profile->alternate_phone,
            // Shown but never editable here. Changing the address you sign in
            // with is a different job with different safeguards.
            'email' => $user->email,
            'alternate_email' => $profile->alternate_email,
            'avatar_url' => $profile->avatarUrl(),
            'initials' => $user->initials,

            'bank_name' => $profile->bank_name,
            'bank_code' => $this->bankCode($profile),
            'account_number' => $profile->account_number,
            'account_name' => $profile->account_name,
            'bvn' => $profile->bvn,
            'swift_code' => $profile->swift_code,
            'sort_code' => $profile->sort_code,
            'annual_rent' => $profile->annual_rent,
            'rsa_number' => $profile->rsa_number,
            'pfa_name' => $profile->pfa_name,
            'tax_identification_number' => $profile->tax_identification_number,
            'nhf_number' => $profile->nhf_number,
        ];
    }

    /**
     * The three family lists, each under its own key.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function relations(User $user): array
    {
        return collect(RelationKind::cases())
            ->mapWithKeys(fn (RelationKind $kind): array => [
                $kind->value => $user->relations
                    ->where('kind', $kind)
                    ->map(fn (EmployeeRelation $relation): array => [
                        'id' => $relation->id,
                        'kind' => $relation->kind->value,
                        'name' => $relation->name,
                        'relationship' => $relation->relationship,
                        'phone' => $relation->phone,
                        'email' => $relation->email,
                        'date_of_birth' => $relation->date_of_birth?->toDateString(),
                        'gender' => $relation->gender,
                        'occupation' => $relation->occupation,
                        'address' => $relation->address,
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * The bank's Paystack code. Rows saved before the list came from Paystack
     * hold a name only, so that name is matched to a code for the form to
     * start from; a name Paystack spells differently is left for the person
     * to pick again.
     */
    protected function bankCode(EmployeeProfile $profile): ?string
    {
        if ($profile->bank_code !== null || $profile->bank_name === null) {
            return $profile->bank_code;
        }

        $name = mb_strtolower(trim($profile->bank_name));

        foreach ($this->paystack->banks() as $bank) {
            if (mb_strtolower($bank['name']) === $name) {
                return $bank['code'];
            }
        }

        return null;
    }

    /**
     * Every pick-list the page needs, in the {value, label} shape the select
     * component takes.
     *
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        $flat = fn (string $key): array => array_map(
            fn (string $item): array => ['value' => $item, 'label' => $item],
            config("profile.{$key}", []),
        );

        return [
            'titles' => $flat('titles'),
            'genders' => $flat('genders'),
            'marital_statuses' => $flat('marital_statuses'),
            'blood_groups' => $flat('blood_groups'),
            'genotypes' => $flat('genotypes'),
            'religions' => $flat('religions'),
            'relationships' => $flat('relationships'),
            'address_types' => $flat('address_types'),
            // Paystack's list rather than a fixed one: an account is looked up
            // by the bank's code, and only Paystack's codes will resolve.
            'banks' => array_map(
                fn (array $bank): array => ['value' => $bank['code'], 'label' => $bank['name']],
                $this->paystack->banks(),
            ),
            'pension_administrators' => $flat('pension_administrators'),
            'relation_kinds' => array_map(
                fn (RelationKind $kind): array => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                    'plural' => $kind->plural(),
                ],
                RelationKind::cases(),
            ),
            'countries' => Countries::options(),
            // Keyed by country code. A country missing from here gets a plain
            // text box for its state instead of a dropdown.
            'states' => Countries::stateOptions(),
        ];
    }
}
