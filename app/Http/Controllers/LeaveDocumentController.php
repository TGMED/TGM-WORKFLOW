<?php

namespace App\Http\Controllers;

use App\Models\LeaveDocument;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveDocumentAdded;
use App\Notifications\LeaveDocumentRequested;
use App\Services\LeaveEvidence;
use App\Services\RequestNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents added to a leave request after it was booked, and the request
 * for one. None of it moves the request along the chain or resets it: the
 * approvals already given stand.
 */
class LeaveDocumentController extends Controller
{
    public function __construct(
        protected LeaveEvidence $evidence,
        protected RequestNotifier $notifier,
    ) {}

    /**
     * Add one or more documents to an open leave request.
     */
    public function store(Request $request, LeaveRequest $leave): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Only the requester attaches their own paperwork.
        abort_unless($leave->user_id === $user->id, 403);

        if (! $leave->acceptsDocuments()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'That request has already been decided, so documents can no longer be added to it.',
            ]);
        }

        $request->validate([
            'documents' => ['required', 'array', 'min:1', 'max:5'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:8192'],
        ], [
            'documents.required' => 'Choose at least one file to add.',
            'documents.max' => 'Add up to 5 files at a time.',
            'documents.*.uploaded' => 'That file did not finish uploading, or is bigger than this server takes. Try a smaller one.',
            'documents.*.mimes' => 'Attach a PDF or an image of the document.',
            'documents.*.max' => 'Keep each file under 8 MB.',
        ]);

        /** @var array<int, UploadedFile> $files */
        $files = $request->file('documents', []);

        $names = [];

        foreach ($files as $file) {
            if ($this->evidence->add($leave, $file, $user)) {
                $names[] = $file->getClientOriginalName();
            }
        }

        if ($names === []) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'The document could not be saved. Try again.',
            ]);
        }

        $leave->refresh()->load('user', 'raisedBy', 'leaveType', 'reliefOfficer', 'supervisor', 'approvals');

        // Whoever it is waiting on hears about it, so a record they asked
        // for is looked at rather than found by chance.
        $waiting = $this->notifier->waitingOn($leave)
            ->reject(fn (User $person): bool => $person->id === $user->id);

        Notification::send($waiting, new LeaveDocumentAdded($leave, $names, $user->name));

        return back()->with('toast', [
            'type' => 'success',
            'message' => count($names) === 1
                ? "Added {$names[0]} to the request."
                : 'Added '.count($names).' documents to the request.',
        ]);
    }

    /**
     * Download a document added after booking.
     */
    public function show(Request $request, LeaveRequest $leave, LeaveDocument $document): StreamedResponse
    {
        abort_unless($document->leave_request_id === $leave->id, 404);
        abort_unless($leave->evidenceVisibleTo($request->user()), 403);

        $disk = Storage::disk(LeaveEvidence::DISK);

        abort_unless($disk->exists($document->path), 404);

        return $disk->download($document->path, $document->name);
    }

    /**
     * Ask the requester to add a document, such as a medical record, before
     * the request is decided. Only someone the request is waiting on, or a
     * super admin, may ask.
     */
    public function ask(Request $request, LeaveRequest $leave): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($leave->mayAskForDocumentFrom($user), 403);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $leave->load('user', 'raisedBy', 'leaveType');

        $people = collect([$leave->user, $leave->raisedBy])
            ->filter()
            ->reject(fn (User $person): bool => $person->id === $user->id)
            ->unique('id');

        Notification::send($people, new LeaveDocumentRequested($leave, $user->name, $validated['note'] ?? null));

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Asked {$leave->user->name} to add a document. The request stays where it is until you decide it.",
        ]);
    }
}
