<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\RequisitionStatus;
use App\Enums\RetirementStatus;
use App\Models\Attachment;
use App\Models\Location;
use App\Models\Requisition;
use App\Models\Role;
use App\Models\User;
use App\Notifications\RequisitionUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Requisitions from raising to retirement.
 */
class RequisitionTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->location = Location::factory()->create();
        Storage::fake('local');
    }

    private function staff(): User
    {
        return User::factory()->create(['location_id' => $this->location->id]);
    }

    private function finance(): User
    {
        $role = Role::query()->create(['slug' => 'finance', 'name' => 'Finance', 'is_system' => false]);
        $role->syncPermissions([Permission::ManageRequisitions]);

        return User::factory()->roles($role->slug)->create(['location_id' => $this->location->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Toner for the finance printer',
            'purpose' => 'Two black cartridges from the usual supplier.',
            'amount' => 85000,
            'bank_code' => '058',
            'account_number' => '0123456789',
            ...$overrides,
        ];
    }

    public function test_raising_one_names_the_payee_as_the_bank_holds_it(): void
    {
        Notification::fake();
        $this->fakeAccountLookup('ADEBAYO SUPPLIES LTD');
        $finance = $this->finance();
        $staff = $this->staff();

        $this->actingAs($staff)
            ->post('/requisitions', $this->payload(['account_name' => 'Somebody Else']))
            ->assertSessionHasNoErrors();

        $requisition = Requisition::query()->sole();
        $this->assertSame('ADEBAYO SUPPLIES LTD', $requisition->account_name);
        $this->assertSame('Guaranty Trust Bank', $requisition->bank_name);
        $this->assertSame('REQ-'.str_pad((string) $requisition->id, 5, '0', STR_PAD_LEFT), $requisition->reference);
        $this->assertSame(0, Attachment::query()->count());

        Notification::assertSentTo($finance, RequisitionUpdate::class);
    }

    public function test_documents_are_optional_but_kept_when_given(): void
    {
        Notification::fake();
        $this->fakeAccountLookup('ADEBAYO SUPPLIES LTD');
        $staff = $this->staff();

        $this->actingAs($staff)
            ->post('/requisitions', $this->payload([
                'documents' => [UploadedFile::fake()->create('quote.pdf', 200, 'application/pdf')],
            ]))
            ->assertSessionHasNoErrors();

        $attachment = Attachment::query()->sole();
        Storage::disk('local')->assertExists($attachment->path);

        $this->actingAs($staff)->get("/attachments/{$attachment->id}")->assertOk();
        $this->actingAs($this->staff())->get("/attachments/{$attachment->id}")->assertForbidden();
    }

    public function test_an_account_the_bank_does_not_know_is_refused(): void
    {
        $this->fakeAccountLookup(null);

        $this->actingAs($this->staff())
            ->post('/requisitions', $this->payload())
            ->assertSessionHasErrors('account_number');

        $this->assertSame(0, Requisition::query()->count());
    }

    public function test_finance_approves_pays_and_closes_it(): void
    {
        Notification::fake();
        $finance = $this->finance();
        $staff = $this->staff();
        $requisition = Requisition::factory()->create(['requester_id' => $staff->id, 'amount' => 100000]);

        $this->actingAs($finance)->post("/admin/requisitions/{$requisition->id}/decision", ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->actingAs($finance)->post("/admin/requisitions/{$requisition->id}/payment", ['payment_reference' => 'TRF-991'])->assertSessionHasNoErrors();
        $this->assertSame(RequisitionStatus::Paid, $requisition->refresh()->status);

        $this->actingAs($staff)
            ->post("/requisitions/{$requisition->id}/retirement", ['amount_spent' => 92500, 'notes' => 'Change of 7,500 returned.'])
            ->assertSessionHasNoErrors();

        $retirement = $requisition->refresh()->retirement;
        $this->assertSame('7500.00', $retirement->balance());

        $this->actingAs($finance)
            ->post("/admin/requisitions/{$requisition->id}/retirement-review", ['decision' => 'accepted'])
            ->assertSessionHasNoErrors();

        $this->assertSame(RequisitionStatus::Retired, $requisition->refresh()->status);
        Notification::assertSentTo($staff, RequisitionUpdate::class);
    }

    public function test_a_retirement_sent_back_can_be_corrected(): void
    {
        $finance = $this->finance();
        $staff = $this->staff();
        $requisition = Requisition::factory()->approved()->create(['requester_id' => $staff->id]);

        $this->actingAs($staff)->post("/requisitions/{$requisition->id}/retirement", ['amount_spent' => 1000]);
        $this->actingAs($finance)
            ->post("/admin/requisitions/{$requisition->id}/retirement-review", ['decision' => 'queried', 'note' => 'Attach the receipt.'])
            ->assertSessionHasNoErrors();

        $this->actingAs($staff)
            ->post("/requisitions/{$requisition->id}/retirement", [
                'amount_spent' => 1000,
                'documents' => [UploadedFile::fake()->image('receipt.jpg')],
            ])
            ->assertSessionHasNoErrors();

        $retirement = $requisition->refresh()->retirement;
        $this->assertSame(RetirementStatus::Pending, $retirement->status);
        $this->assertSame(1, $retirement->attachments()->count());
    }

    public function test_a_pending_requisition_cannot_be_retired(): void
    {
        $staff = $this->staff();
        $requisition = Requisition::factory()->create(['requester_id' => $staff->id]);

        $this->actingAs($staff)->post("/requisitions/{$requisition->id}/retirement", ['amount_spent' => 10]);

        $this->assertNull($requisition->refresh()->retirement);
    }

    public function test_declining_needs_a_reason(): void
    {
        $requisition = Requisition::factory()->create();

        $this->actingAs($this->finance())
            ->post("/admin/requisitions/{$requisition->id}/decision", ['decision' => 'declined'])
            ->assertSessionHasErrors('note');
    }

    public function test_the_desk_needs_its_own_permission(): void
    {
        $this->actingAs($this->staff())->get('/admin/requisitions')->assertForbidden();
    }

    public function test_the_desk_lists_what_needs_finance(): void
    {
        Requisition::factory()->create();
        Requisition::factory()->create(['status' => RequisitionStatus::Retired]);

        $this->actingAs($this->finance())
            ->get('/admin/requisitions')
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Requisitions')
                ->has('requisitions.data', 1)
                ->where('totals.pending.count', 1));
    }

    public function test_staff_see_their_own_with_the_bank_list(): void
    {
        $staff = $this->staff();
        Requisition::factory()->create(['requester_id' => $staff->id]);
        Requisition::factory()->create();

        $this->actingAs($staff)
            ->get('/requisitions')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Requisitions')
                ->has('requisitions', 1)
                ->has('banks', 3));
    }
}
