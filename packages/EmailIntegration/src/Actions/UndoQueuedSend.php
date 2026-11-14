<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Actions\Task\DeleteTask;
use App\Models\User;
use App\Support\CurrentWorkspace;
use App\Support\PlainText;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailFollowUp;
use RuntimeException;

final readonly class UndoQueuedSend
{
    public function __construct(private DeleteTask $deleteTask, private CurrentWorkspace $currentWorkspace) {}

    public function execute(User $user, string $emailId): Email
    {
        return DB::transaction(function () use ($user, $emailId): Email {
            $email = Email::query()
                ->where('user_id', $user->getKey())
                ->where('workspace_id', $user->current_workspace_id)
                ->whereKey($emailId)
                ->lockForUpdate()
                ->firstOrFail();

            throw_if($email->status !== EmailStatus::QUEUED || $email->scheduled_for === null || ! $email->scheduled_for->isFuture(), RuntimeException::class, 'The send window has closed.');

            $html = $this->withoutReplyQuote((string) $email->body?->body_html);
            $email->body()->update(['body_html' => $html, 'body_text' => PlainText::paragraphsFromHtml($html)]);
            $email->update([
                'status' => EmailStatus::DRAFT,
                'folder' => EmailFolder::Drafts,
                'privacy_tier' => EmailPrivacyTier::PRIVATE,
                'scheduled_for' => null,
                'draft_version' => $email->draft_version + 1,
                'snippet' => mb_substr(PlainText::fromHtml($html), 0, 255),
            ]);

            $this->currentWorkspace->within($email->workspace_id, function () use ($email, $user): void {
                EmailFollowUp::query()->where('email_id', $email->getKey())->with('task')
                    ->each(function (EmailFollowUp $followUp) use ($user): void {
                        if ($followUp->task !== null) {
                            $this->deleteTask->execute($user, $followUp->task);
                        }
                        $followUp->delete();
                    });
            });

            return $email->refresh();
        });
    }

    private function withoutReplyQuote(string $html): string
    {
        $document = HTMLDocument::createFromString('<body>'.$html, LIBXML_NOERROR, 'UTF-8');

        foreach ($document->querySelectorAll('[data-relaticle-quote]') as $quote) {
            $previous = $quote->previousSibling;
            if ($previous instanceof Element && $previous->localName === 'br') {
                $previous->remove();
            }
            $quote->remove();
        }

        return $document->body->innerHTML;
    }
}
