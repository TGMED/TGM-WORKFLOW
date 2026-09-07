<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Services\LeaveEvidence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveEvidenceController extends Controller
{
    /**
     * Hand back the document behind a request, to the handful of people
     * entitled to see it.
     */
    public function show(Request $request, LeaveRequest $leave): StreamedResponse
    {
        abort_unless($leave->hasEvidence(), 404);
        abort_unless($leave->evidenceVisibleTo($request->user()), 403);

        $disk = Storage::disk(LeaveEvidence::DISK);

        abort_unless($disk->exists($leave->evidence_path), 404);

        return $disk->download($leave->evidence_path, $leave->evidence_name ?? 'evidence');
    }
}
