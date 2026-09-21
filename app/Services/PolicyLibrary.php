<?php

namespace App\Services;

use App\Models\Policy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Where policy documents are kept.
 *
 * On the private disk and served through a controller, the way leave evidence
 * is. A handbook is not a secret, but a guessable URL to it would be, and the
 * same rule for every upload is one rule to get right.
 */
class PolicyLibrary
{
    public const DISK = 'local';

    protected const DIRECTORY = 'policies';

    /**
     * Publish a document. Where it replaces one already in force, the old
     * version is stood down in the same breath and points at its replacement,
     * so the two can never both be the current rule.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws \Throwable
     */
    public function publish(UploadedFile $file, array $attributes, ?Policy $supersedes = null): Policy
    {
        $path = $file->store(self::DIRECTORY, self::DISK);

        if ($path === false) {
            throw new \RuntimeException('The policy document could not be stored.');
        }

        return DB::transaction(function () use ($file, $attributes, $supersedes, $path): Policy {
            $policy = Policy::query()->create([
                ...$attributes,
                'file_path' => $path,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize() ?: 0,
                'mime_type' => $file->getMimeType(),
                'supersedes_id' => $supersedes?->id,
            ]);

            // Retired rather than deleted: a case may still turn on what the
            // old version said, and the file stays readable to whoever is
            // looking back.
            $supersedes?->update(['is_active' => false]);

            return $policy;
        });
    }

    /**
     * Take a policy out of force without destroying it.
     */
    /**
     * The document, to be read in the browser rather than saved: opened in a
     * tab, it is the page somebody wanted, and a file in their downloads is
     * one more copy of a rule that may since have changed.
     */
    public function open(Policy $policy): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);

        abort_unless($disk->exists($policy->file_path), 404);

        return $disk->response($policy->file_path, $policy->file_name, [
            'Content-Type' => $policy->mime_type ?: 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function retire(Policy $policy): void
    {
        $policy->update(['is_active' => false]);
    }

    /**
     * Remove a policy and its file for good. Only for something published by
     * mistake: anything staff have been held to should be retired instead.
     */
    public function destroy(Policy $policy): void
    {
        $path = $policy->file_path;

        $policy->delete();

        Storage::disk(self::DISK)->delete($path);
    }
}
