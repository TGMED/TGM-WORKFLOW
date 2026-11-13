<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\User;
use App\Notifications\LeaveDocumentAdded;
use App\Notifications\LeaveDocumentRequested;
use App\Services\LeaveEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Documents can be added to a leave request while it is still open, on top of
 * whatever was attached at booking, and an approver can ask for one.
 */
class LeaveDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private User $requester;

    private User $relief;

    private User $manager;

    private User $admin;

    private LeaveRequest $leave;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(LeaveEvidence::DISK);

        $this->location = Location::factory()->create();
        $this->requester = User::factory()->create(['location_id' => $this->location->id]);
        $this->relief = User::factory()->create(['location_id' => $this->location->id]);
        $this->manager = User::factory()->approver()->create(['location_id' => $this->location->id]);
        $this->admin = User::factory()->superAdmin()->create(['location_id' => $this->location->id]);

        $this->leave = LeaveRequest::factory()
            ->chained($this->relief, $this->manager)
            ->create([
                'user_id' => $this->requester->id,
                'leave_type_id' => LeaveType::factory()->needsEvidence()->create()->id,
                'evidence_path' => 'leave-evidence/drugs.jpg',
                'evidence_name' => 'drugs.jpg',
            ]);

        Storage::disk(LeaveEvidence::DISK)->put('leave-evidence/drugs.jpg', 'photo');
    }

    private function pdf(string $name = 'medical-record.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 40, 'application/pdf');
    }

    private function approve(User $who): void
    {
        $this->actingAs($who)->post("/approvals/leave/{$this->leave->id}", ['decision' => 'approved']);
    }

    public function test_the_requester_adds_a_document_without_losing_the_first_one(): void
    {
        $this->actingAs($this->requester)
            ->post("/leave/{$this->leave->id}/documents", ['documents' => [$this->pdf()]])
            ->assertSessionHasNoErrors();

        $this->leave->refresh();

        $this->assertSame('drugs.jpg', $this->leave->evidence_name);
        Storage::disk(LeaveEvidence::DISK)->assertExists('leave-evidence/drugs.jpg');

        $document = $this->leave->documents()->sole();
        $this->assertSame('medical-record.pdf', $document->name);
        $this->assertSame($this->requester->id, $document->uploaded_by_id);
        Storage::disk(LeaveEvidence::DISK)->assertExists($document->path);
    }

    public function test_adding_a_document_keeps_the_approvals_already_given(): void
    {
        $this->approve($this->relief);
        $this->approve($this->manager);

        $this->actingAs($this->requester)
            ->post("/leave/{$this->leave->id}/documents", ['documents' => [$this->pdf()]])
            ->assertSessionHasNoErrors();

        $this->leave->refresh()->load('approvals');

        $this->assertSame(RequestStatus::Pending, $this->leave->status);
        $this->assertTrue($this->leave->awaitsAdmin());
        $this->assertCount(2, $this->leave->currentDecisions());
    }

    public function test_whoever_it_is_waiting_on_is_told_a_document_was_added(): void
    {
        Notification::fake();

        $this->approve($this->relief);
        $this->approve($this->manager);

        $this->actingAs($this->requester)
            ->post("/leave/{$this->leave->id}/documents", ['documents' => [$this->pdf(), $this->pdf('scan.pdf')]]);

        Notification::assertSentTo($this->admin, LeaveDocumentAdded::class);
        Notification::assertNotSentTo($this->manager, LeaveDocumentAdded::class);
        $this->assertSame(2, $this->leave->documents()->count());
    }

    public function test_leave_types_that_take_no_paperwork_stay_as_they_were(): void
    {
        Notification::fake();

        $annual = LeaveRequest::factory()
            ->chained($this->relief, $this->manager)
            ->create(['user_id' => $this->requester->id]);

        $this->actingAs($this->requester)
            ->post("/leave/{$annual->id}/documents", ['documents' => [$this->pdf()]]);

        $this->assertSame(0, $annual->documents()->count());

        $this->actingAs($this->admin)
            ->post("/leave/{$annual->id}/documents/ask")
            ->assertForbidden();

        Notification::assertNothingSent();

        $this->actingAs($this->requester)->get('/leave')
            ->assertInertia(fn ($page) => $page->where('requests', function ($rows) use ($annual): bool {
                $rows = collect($rows);

                return $rows->firstWhere('id', $annual->id)['can_add_document'] === false
                    && $rows->firstWhere('id', $this->leave->id)['can_add_document'] === true;
            }));
    }

    public function test_only_the_requester_can_attach_documents(): void
    {
        $this->approve($this->relief);
        $this->approve($this->manager);

        foreach ([$this->relief, $this->manager, $this->admin] as $other) {
            $this->actingAs($other)
                ->post("/leave/{$this->leave->id}/documents", ['documents' => [$this->pdf()]])
                ->assertForbidden();
        }

        $this->assertSame(0, $this->leave->documents()->count());

        // They can still read what is there and ask for more.
        $this->actingAs($this->admin)->get('/approvals')
            ->assertInertia(fn ($page) => $page
                ->where('leave.0.documents.0.name', 'drugs.jpg')
                ->where('leave.0.can_ask_document', true)
                ->missing('leave.0.can_add_document'));
    }

    public function test_documents_cannot_be_added_once_the_leave_is_decided(): void
    {
        $this->leave->update(['status' => RequestStatus::Approved]);

        $this->actingAs($this->requester)
            ->post("/leave/{$this->leave->id}/documents", ['documents' => [$this->pdf()]]);

        $this->assertSame(0, $this->leave->documents()->count());
    }

    public function test_a_colleague_with_no_part_in_it_cannot_add_or_open_documents(): void
    {
        $stranger = User::factory()->create(['location_id' => $this->location->id]);

        $this->actingAs($this->requester)
            ->post("/leave/{$this->leave->id}/documents", ['documents' => [$this->pdf()]]);

        $document = $this->leave->documents()->sole();

        $this->actingAs($stranger)
            ->post("/leave/{$this->leave->id}/documents", ['documents' => [$this->pdf()]])
            ->assertForbidden();

        $this->actingAs($stranger)
            ->get("/leave/{$this->leave->id}/documents/{$document->id}")
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get("/leave/{$this->leave->id}/documents/{$document->id}")
            ->assertOk();
    }

    public function test_only_pdfs_and_images_are_taken(): void
    {
        $this->actingAs($this->requester)
            ->post("/leave/{$this->leave->id}/documents", [
                'documents' => [UploadedFile::fake()->create('notes.exe', 10)],
            ])
            ->assertSessionHasErrors('documents.0');

        $this->assertSame(0, $this->leave->documents()->count());
    }

    public function test_the_approver_and_super_admin_see_every_document(): void
    {
        $this->actingAs($this->requester)
            ->post("/leave/{$this->leave->id}/documents", ['documents' => [$this->pdf()]]);

        $this->approve($this->relief);

        $this->actingAs($this->manager)->get('/approvals')
            ->assertInertia(fn ($page) => $page
                ->where('leave.0.documents.0.name', 'drugs.jpg')
                ->where('leave.0.documents.0.at_booking', true)
                ->where('leave.0.documents.1.name', 'medical-record.pdf')
                ->where('leave.0.can_ask_document', true));

        $this->actingAs($this->requester)->get('/leave')
            ->assertInertia(fn ($page) => $page
                ->where('requests.0.can_add_document', true)
                ->has('requests.0.documents', 2));
    }

    public function test_an_approver_can_ask_the_requester_for_a_document(): void
    {
        Notification::fake();

        $this->approve($this->relief);

        $this->actingAs($this->manager)
            ->post("/leave/{$this->leave->id}/documents/ask", ['note' => 'A medical report from the hospital, please.'])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo(
            $this->requester,
            LeaveDocumentRequested::class,
            fn (LeaveDocumentRequested $n): bool => in_array(
                'Their note: “A medical report from the hospital, please.”',
                $n->toMail($this->requester)->introLines,
                true,
            ),
        );

        // Asking moves nothing.
        $this->assertSame(RequestStatus::Pending, $this->leave->refresh()->status);
    }

    public function test_the_cover_cannot_ask_for_a_document(): void
    {
        Notification::fake();

        $this->actingAs($this->relief)->get('/approvals')
            ->assertInertia(fn ($page) => $page
                ->where('leave.0.stage', 'relief')
                ->where('leave.0.can_ask_document', false));

        $this->actingAs($this->relief)
            ->post("/leave/{$this->leave->id}/documents/ask")
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_a_super_admin_can_ask_before_their_turn_but_a_colleague_cannot(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post("/leave/{$this->leave->id}/documents/ask")
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($this->requester, LeaveDocumentRequested::class);

        $this->actingAs(User::factory()->create(['location_id' => $this->location->id]))
            ->post("/leave/{$this->leave->id}/documents/ask")
            ->assertForbidden();
    }

    public function test_editing_with_a_new_file_adds_it_rather_than_replacing(): void
    {
        $this->actingAs($this->requester)
            ->put("/leave/{$this->leave->id}", [
                'leave_type_id' => $this->leave->leave_type_id,
                'supervisor_id' => $this->manager->id,
                'relief_officer_id' => $this->relief->id,
                'start_date' => $this->leave->start_date->toDateString(),
                'end_date' => $this->leave->end_date->toDateString(),
                'reason' => 'Still unwell',
                'evidence' => $this->pdf('hospital-letter.pdf'),
            ]);

        $this->leave->refresh();

        $this->assertSame('drugs.jpg', $this->leave->evidence_name);
        $this->assertSame(['hospital-letter.pdf'], $this->leave->documents()->pluck('name')->all());
    }
}
