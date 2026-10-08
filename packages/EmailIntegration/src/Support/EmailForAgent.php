<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Models\User;
use Dom\HTMLDocument;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Services\PrivacyService;

final readonly class EmailForAgent
{
    private const int BODY_LIMIT = 20_000;

    public function __construct(private PrivacyService $privacy) {}

    /** @return array<string, mixed>|null */
    public function summary(Email $email, User $viewer): ?array
    {
        $tier = $this->privacy->effectiveTier($email, $viewer);

        return $tier instanceof EmailPrivacyTier ? $this->summaryAt($tier, $email, $viewer) : null;
    }

    /** @return array<string, mixed>|null */
    public function detail(Email $email, User $viewer): ?array
    {
        $tier = $this->privacy->effectiveTier($email, $viewer);

        if (! $tier instanceof EmailPrivacyTier) {
            return null;
        }

        $body = $tier->showsBody() ? $this->bodyText($email) : null;

        return [
            ...$this->summaryAt($tier, $email, $viewer),
            'body_text' => $body === null ? null : mb_substr($body, 0, self::BODY_LIMIT),
            'body_truncated' => $body !== null && mb_strlen($body) > self::BODY_LIMIT,
            'attachments' => $tier->showsBody()
                ? $email->downloadAttachments()
                    ->map(fn (EmailAttachment $attachment): array => [
                        'filename' => $attachment->filename,
                        'mime_type' => $attachment->mime_type,
                        'size' => $attachment->size,
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     email: string,
     *     name: ?string,
     *     provider: string,
     *     is_default: bool,
     *     can_send: bool
     * }
     */
    public function mailbox(ConnectedAccount $account): array
    {
        return [
            'id' => (string) $account->getKey(),
            'email' => $account->email_address,
            'name' => $account->display_name,
            'provider' => $account->provider->value,
            'is_default' => (bool) $account->is_default,
            'can_send' => $account->isSendable(),
        ];
    }

    public function textFromHtml(string $html): string
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');

        foreach ($document->querySelectorAll('head, style, script, template, noscript') as $hidden) {
            $hidden->remove();
        }

        foreach ($document->querySelectorAll('br') as $lineBreak) {
            $lineBreak->replaceWith("\n");
        }

        foreach ($document->querySelectorAll('td, th') as $cell) {
            $cell->append(' ');
        }

        foreach ($document->querySelectorAll('p, div, li, tr, h1, h2, h3, h4, h5, h6, blockquote') as $block) {
            $block->append("\n");
        }

        $lines = Str::of((string) $document->body?->textContent)
            ->replaceMatches('/[ \t\x{00A0}]+/u', ' ')
            ->explode("\n")
            ->map(fn (string $line): string => trim($line))
            ->implode("\n");

        return Str::of($lines)
            ->replaceMatches('/\n{3,}/', "\n\n")
            ->trim()
            ->toString();
    }

    /**
     * @return array{
     *     id: string,
     *     thread_id: ?string,
     *     direction: string,
     *     sent_at: ?string,
     *     access: string,
     *     subject: ?string,
     *     snippet: ?string,
     *     has_attachments: bool,
     *     participants: list<array{role: string, name: ?string, email: string}>
     * }
     */
    private function summaryAt(EmailPrivacyTier $tier, Email $email, User $viewer): array
    {
        $ownsMailbox = $email->user_id === $viewer->getKey();

        return [
            'id' => (string) $email->getKey(),
            'thread_id' => $email->thread_id,
            'direction' => $email->direction->value,
            'sent_at' => $email->sent_at?->toIso8601String(),
            'access' => $tier->value,
            'subject' => $tier->showsSubject() ? $email->subject : null,
            'snippet' => $tier->showsBody() ? $email->snippet : null,
            'has_attachments' => (bool) $email->has_attachments,
            'participants' => array_values($email->participants
                ->filter(fn (EmailParticipant $participant): bool => $this->isListed($participant->role, $tier, $ownsMailbox))
                ->map(fn (EmailParticipant $participant): array => [
                    'role' => $participant->role->value,
                    'name' => $participant->name,
                    'email' => $participant->email_address,
                ])
                ->all()),
        ];
    }

    private function isListed(EmailParticipantRole $role, EmailPrivacyTier $tier, bool $ownsMailbox): bool
    {
        return match ($role) {
            EmailParticipantRole::BCC => $ownsMailbox,
            EmailParticipantRole::CC => $tier->showsBody(),
            default => true,
        };
    }

    private function bodyText(Email $email): ?string
    {
        if ($email->body === null) {
            return null;
        }

        if (filled($email->body->body_text)) {
            return $email->body->body_text;
        }

        return $this->textFromHtml((string) $email->body->body_html);
    }
}
