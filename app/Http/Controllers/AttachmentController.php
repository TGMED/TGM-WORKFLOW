<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\Attachments;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A document on a requisition or retirement, for the requester and finance.
 */
class AttachmentController extends Controller
{
    public function show(Request $request, Attachment $attachment, Attachments $attachments): StreamedResponse
    {
        abort_unless($attachments->mayOpen($attachment, $request->user()), 403);
        abort_unless(Attachments::disk()->exists($attachment->path), 404);

        return Attachments::disk()->download($attachment->path, $attachment->name);
    }
}
