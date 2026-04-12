<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Services\GmailService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class EmailAttachmentController
{
    public function __invoke(Request $request, string $attachmentId): StreamedResponse
    {
        /** @var EmailAttachment $attachment */
        $attachment = EmailAttachment::with('email.connectedAccount')->findOrFail($attachmentId);

        $email = $attachment->email;

        abort_if($email === null, 404);

        /** @var User $user */
        $user = $request->user();

        // Verify the user belongs to the same team as the email
        abort_unless($user->current_team_id === $email->team_id, 403);

        // Respect privacy — body access is required to download attachments
        abort_unless($user->can('viewBody', $email), 403);

        abort_if(blank($attachment->provider_attachment_id), 404, 'Attachment is not available for download.');

        $connectedAccount = $email->connectedAccount;

        abort_if($connectedAccount === null, 404, 'Email account is no longer connected.');

        // File downloads may legitimately take longer than the default 30 s PHP limit —
        // raise it before the Gmail API call so large attachments don't time out.
        set_time_limit(120);

        // Fetch the binary before streaming so any API/auth errors surface as proper HTTP responses
        $binary = new GmailService($connectedAccount)
            ->downloadAttachment($email->provider_message_id, $attachment->provider_attachment_id);

        $filename = $attachment->filename ?? 'attachment';
        $mimeType = $attachment->mime_type ?? 'application/octet-stream';

        return response()->streamDownload(
            function () use ($binary): void {
                echo $binary;
            },
            $filename,
            ['Content-Type' => $mimeType],
        );
    }
}
