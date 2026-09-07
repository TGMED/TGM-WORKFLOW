<?php

namespace App\Services;

use App\Models\LeaveRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The supporting document behind a leave request. It lives on the private
 * disk: sick papers and death certificates are not served off a guessable
 * URL, so every read goes through a controller that checks who is asking.
 */
class LeaveEvidence
{
    public const DISK = 'local';

    protected const DIRECTORY = 'leave-evidence';

    /**
     * Attach a newly uploaded document, replacing whatever it supersedes.
     * The old file is only dropped once the new one is safely written, so a
     * failed upload cannot leave a request with no paperwork at all.
     */
    public function attach(LeaveRequest $leave, UploadedFile $file): bool
    {
        $path = $file->store(self::DIRECTORY, self::DISK);

        if ($path === false) {
            return false;
        }

        $previous = $leave->evidence_path;

        $leave->update([
            'evidence_path' => $path,
            'evidence_name' => $file->getClientOriginalName(),
        ]);

        $this->forget($previous);

        return true;
    }

    public function forget(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
