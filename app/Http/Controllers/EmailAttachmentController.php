<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class EmailAttachmentController
{
    public function __construct(
        private MailServiceFactoryInterface $mailServiceFactory,
    ) {}

    public function __invoke(Request $request, string $attachmentId): StreamedResponse
    {
        $attachment = $this->authorizedAttachment($request, $attachmentId);

        return $this->stream($this->attachmentBinary($attachment), $attachment->filename ?? 'attachment');
    }

    public function inline(Request $request, string $attachmentId): Response
    {
        $attachment = $this->authorizedAttachment($request, $attachmentId);

        abort_unless($attachment->is_inline, 404);
        abort_unless(str_starts_with((string) $attachment->mime_type, 'image/'), 404);

        return response($this->attachmentBinary($attachment), 200, [
            'Content-Type' => (string) $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function authorizedAttachment(Request $request, string $attachmentId): EmailAttachment
    {
        /** @var EmailAttachment $attachment */
        $attachment = EmailAttachment::with('email.connectedAccount')->findOrFail($attachmentId);

        $email = $attachment->email;

        abort_if($email === null, 404);

        /** @var User $user */
        $user = $request->user();

        // Verify the user belongs to the same team as the email.
        abort_unless($user->current_team_id === $email->team_id, 403);

        // Respect privacy; body access is required to download or render attachments.
        abort_unless($user->can('viewBody', $email), 403);

        return $attachment;
    }

    private function attachmentBinary(EmailAttachment $attachment): string
    {
        // Files the user attached here (a draft's own uploads) never went through a
        // provider, so there is no provider id to fetch them by; they are already on
        // our disk and stream straight back.
        if (blank($attachment->provider_attachment_id)) {
            $storagePath = $attachment->storage_path;

            abort_if(blank($storagePath), 404, 'Attachment is not available for download.');

            $disk = Storage::disk(EmailAttachment::DISK);

            abort_unless($disk->exists($storagePath), 404, 'Attachment is not available for download.');

            return $disk->get($storagePath) ?? '';
        }

        $email = $attachment->email;

        // The provider download needs the parent message id; outbound/partially-synced
        // rows can have a null provider_message_id, so guard for a clean 404 rather than
        // passing null into the string-typed downloadAttachment() (TypeError -> 500).
        abort_if($email === null || blank($email->provider_message_id), 404, 'Attachment is not available for download.');

        $connectedAccount = $email->connectedAccount;

        abort_if($connectedAccount === null, 404, 'Email account is no longer connected.');

        // File downloads may legitimately take longer than the default 30 s PHP limit;
        // raise it before the provider API call so large attachments don't time out.
        set_time_limit(120);

        // Resolve the provider-appropriate mail service (Gmail or Microsoft Graph) and
        // fetch the binary before streaming so any API/auth errors surface as proper
        // HTTP responses rather than mid-stream failures.
        $mailService = $this->mailServiceFactory->make($connectedAccount);

        return $mailService->downloadAttachment($email->provider_message_id, $attachment->provider_attachment_id);
    }

    /**
     * Force a generic binary content-type and tell browsers not to MIME-sniff.
     * Sender-controlled mime types (image/svg+xml, text/html) combined with browser
     * sniffing could otherwise lead to scripts executing in the app origin if the
     * disposition header were ever weakened.
     */
    private function stream(string $binary, string $filename): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($binary): void {
                echo $binary;
            },
            $filename,
            [
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
        );
    }
}
