<?php

namespace App\Services;

use App\Models\Report;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The optional file behind a report: a photograph, a screenshot, a letter.
 * Same handling as leave evidence — private disk, every read through a
 * controller that checks who is asking — for a stronger reason: the file may
 * be the only thing that identifies the person who sent it.
 */
class ReportEvidence
{
    public const DISK = 'local';

    protected const DIRECTORY = 'report-evidence';

    /**
     * Attach a newly uploaded file. Reports are filed once and not edited, so
     * unlike leave evidence there is nothing here to supersede.
     */
    public function attach(Report $report, UploadedFile $file): bool
    {
        $path = $file->store(self::DIRECTORY, self::DISK);

        if ($path === false) {
            return false;
        }

        $report->update([
            'evidence_path' => $path,
            // The original filename can carry a name the reporter did not mean
            // to give away, so only the extension is kept.
            'evidence_name' => 'evidence.'.$file->getClientOriginalExtension(),
        ]);

        return true;
    }

    public function forget(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
