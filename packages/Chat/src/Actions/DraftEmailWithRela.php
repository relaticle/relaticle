<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Relaticle\Chat\Jobs\WriteRelaEmailDraft;
use Relaticle\Chat\Services\CreditService;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ComposedEmailsQuery;
use Relaticle\EmailIntegration\Queries\ConversationFactsQuery;
use Throwable;

final readonly class DraftEmailWithRela
{
    public function __construct(private CreditService $credits, private ComposedEmailsQuery $emails, private ConversationFactsQuery $facts) {}

    public function execute(User $user, string $draftId, int $draftVersion, string $instruction): string
    {
        $draft = $this->emails->draft($user, $draftId);
        abort_unless($draft instanceof Email && $draft->creation_source !== EmailCreationSource::MASS_SEND, 404);
        abort_unless($draft->draft_version === $draftVersion, 409);
        $instruction = trim($instruction);
        if ($instruction === '' || mb_strlen($instruction) > 2000) {
            throw ValidationException::withMessages(['instruction' => __('filament/emails/composer.rela.invalid_instruction')]);
        }
        $source = $draft->in_reply_to === null ? null : Email::query()->forWorkspace($draft->workspace_id)->where('rfc_message_id', $draft->in_reply_to)->first();
        if ($source?->conversation_key !== null && $this->facts->replyDraftBlocker($user, $source->conversation_key) !== null) {
            throw ValidationException::withMessages(['instruction' => __('filament/emails/composer.rela.reply_blocked')]);
        }

        return Cache::lock("email-rela:lock:{$draftId}", 5)->block(2, function () use ($user, $draft, $draftVersion, $instruction): string {
            $active = Cache::get("email-rela:active:{$draft->getKey()}");
            if (is_string($active)) {
                return $active;
            }
            $token = 'draft-'.Str::ulid();
            $workspace = $user->currentWorkspace;
            abort_unless($workspace !== null, 403);
            if (! $this->credits->reserveCredit($workspace, "reserve-{$token}", userId: $user->getKey())) {
                throw ValidationException::withMessages(['instruction' => __('filament/emails/composer.rela.no_credits')]);
            }
            Cache::put("email-rela:active:{$draft->getKey()}", $token, 120);
            Cache::put("email-rela:status:{$token}", 'working', 300);
            try {
                dispatch(new WriteRelaEmailDraft($user->getKey(), $workspace->getKey(), $draft->getKey(), $draftVersion, $instruction, $token))->afterCommit();
            } catch (Throwable $exception) {
                $this->credits->refundReservation($workspace, resolutionKey: "resolve-{$token}");
                Cache::forget("email-rela:active:{$draft->getKey()}");
                Cache::put("email-rela:status:{$token}", 'failed', 300);
                throw $exception;
            }

            return $token;
        });
    }
}
