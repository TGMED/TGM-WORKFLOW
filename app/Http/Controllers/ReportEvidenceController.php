<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Services\ReportEvidence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportEvidenceController extends Controller
{
    /**
     * Hand back the file attached to a report, to the person who filed it and
     * to the administrators who handle reports. Nobody else, and never the
     * person the report is about.
     */
    public function show(Request $request, Report $report): StreamedResponse
    {
        abort_unless($report->hasEvidence(), 404);
        abort_unless($report->evidenceVisibleTo($request->user()), 403);

        $disk = Storage::disk(ReportEvidence::DISK);

        abort_unless($disk->exists($report->evidence_path), 404);

        return $disk->download($report->evidence_path, $report->evidence_name ?? 'evidence');
    }
}
