<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\Attachment;
use App\Models\Requisition;
use App\Models\Retirement;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Supporting documents on requisitions and retirements. On the private disk,
 * like leave evidence: quotes and receipts carry bank details and are not
 * served off a guessable URL.
 */
class Attachments
{
    public const DISK = 'local';

    protected const DIRECTORY = 'requisition-documents';

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function attach(Requisition|Retirement $parent, array $files, User $by): void
    {
        foreach ($files as $file) {
            $path = $file->store(self::DIRECTORY, self::DISK);

            if ($path === false) {
                continue;
            }

            $parent->attachments()->create([
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'uploaded_by_id' => $by->id,
            ]);
        }
    }

    /**
     * The requester, and finance, may open it; nobody else.
     */
    public function mayOpen(Attachment $attachment, User $user): bool
    {
        $requisition = $attachment->requisition();

        return $requisition !== null
            && ($requisition->requester_id === $user->id || $user->hasPermission(Permission::ManageRequisitions));
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public static function listing(Requisition|Retirement $parent): array
    {
        return $parent->attachments
            ->map(fn (Attachment $a): array => ['id' => $a->id, 'name' => $a->name])
            ->values()
            ->all();
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
