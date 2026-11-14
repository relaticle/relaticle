<?php

declare(strict_types=1);

namespace Relaticle\Chat\Jobs;

use App\Models\User;
use App\Support\CurrentWorkspace;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Relaticle\Chat\Agents\EmailDrafter;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\AiModelResolver;
use Relaticle\Chat\Services\CreditService;
use Relaticle\EmailIntegration\Actions\SaveEmailDraftAction;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ComposedEmailsQuery;
use Relaticle\EmailIntegration\Queries\ConversationFactsQuery;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Queries\VisibleEmailsQuery;
use Throwable;

#[Connection('redis-chat')]
#[Queue('chat')]
#[Tries(1)]
#[Timeout(90)]
final class WriteRelaEmailDraft implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $userId, public readonly string $workspaceId, public readonly string $draftId, public readonly int $draftVersion, public readonly string $instruction, public readonly string $token) {}

    public function handle(CurrentWorkspace $currentWorkspace, CreditService $credits): void
    {
        $currentWorkspace->within($this->workspaceId, function () use ($credits): void {
            $user = User::query()->find($this->userId);
            try {
                abort_unless($user instanceof User && $user->belongsToWorkspaceId($this->workspaceId), 403);
                $user->current_workspace_id = $this->workspaceId;
                $user->unsetRelation('currentWorkspace');
                $draft = resolve(ComposedEmailsQuery::class)->draft($user, $this->draftId);
                abort_unless($draft instanceof Email && $draft->draft_version === $this->draftVersion, 409);
                $draft->load(['body', 'participants']);
                $resolved = resolve(AiModelResolver::class)->resolve($user);
                $response = (new EmailDrafter)->prompt($this->prompt($user, $draft), provider: $resolved['provider'], model: $resolved['model']);
                DB::transaction(function () use ($user, $draft, $response, $resolved, $credits): void {
                    $this->save($user, $draft, $response);
                    $credits->settleReservation($user->currentWorkspace, $user, AiCreditType::Draft, $resolved['model'] ?? '', $response->usage->inputTokens, $response->usage->outputTokens,
                        resolutionKey: "resolve-{$this->token}", metadata: ['email_id' => $this->draftId]);
                    AiCreditTransaction::query()->firstOrCreate([
                        'workspace_id' => $this->workspaceId, 'idempotency_key' => "resolve-{$this->token}",
                    ], [
                        'user_id' => $this->userId, 'type' => AiCreditType::Draft, 'model' => $resolved['model'] ?? '',
                        'input_tokens' => $response->usage->inputTokens, 'output_tokens' => $response->usage->outputTokens,
                        'credits_charged' => 0, 'metadata' => ['email_id' => $this->draftId], 'created_at' => now(),
                    ]);
                });
                Cache::put("email-rela:status:{$this->token}", 'done', 300);
            } catch (Throwable $exception) {
                $workspace = resolve(CurrentWorkspace::class)->get();
                if ($workspace !== null) {
                    $credits->refundReservation($workspace, resolutionKey: "resolve-{$this->token}");
                }
                Cache::put("email-rela:status:{$this->token}", 'failed', 300);
                report($exception);
            } finally {
                Cache::forget("email-rela:active:{$this->draftId}");
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        resolve(CurrentWorkspace::class)->within($this->workspaceId, function (): void {
            $workspace = resolve(CurrentWorkspace::class)->get();
            if ($workspace !== null) {
                resolve(CreditService::class)->refundReservation($workspace, resolutionKey: "resolve-{$this->token}");
            }
            Cache::put("email-rela:status:{$this->token}", 'failed', 300);
            Cache::forget("email-rela:active:{$this->draftId}");
        });
    }

    private function prompt(User $user, Email $draft): string
    {
        $context = (string) $draft->body?->body_text;
        if ($draft->in_reply_to !== null) {
            $source = Email::query()->forWorkspace($this->workspaceId)->where('rfc_message_id', $draft->in_reply_to)->with('body')->get()
                ->first(fn (Email $email): bool => $user->can('viewBody', $email));
            abort_unless($source instanceof Email, 403);
            if ($source->conversation_key !== null) {
                abort_unless(resolve(ConversationFactsQuery::class)->replyDraftBlocker($user, $source->conversation_key) === null, 409);
                $messages = resolve(ConversationsQuery::class)->messages($user, $source->conversation_key)
                    ->filter(fn (Email $email): bool => $user->can('viewBody', $email))->take(-20)->load('body');
                $context .= "\nConversation:\n".$messages->map(fn (Email $email): string => ($email->sent_at?->toIso8601String() ?? '').' '.mb_substr((string) $email->body?->body_text, 0, 4000))->implode("\n");
            } else {
                $context .= "\nReplying to: ".($source->body->body_text ?? '');
            }
        }

        $voice = resolve(VisibleEmailsQuery::class)->recentSentTo($user, array_values($draft->participants->where('role', EmailParticipantRole::TO)->pluck('email_address')->all()))
            ->map(fn (Email $email): string => mb_substr((string) $email->body?->body_text, 0, 2000))->implode("\n");

        return "Writing instruction: {$this->instruction}\nSubject: {$draft->subject}\n<email_context>\n{$context}\n</email_context>\n<sender_style_examples>\n{$voice}\n</sender_style_examples>";
    }

    private function save(User $user, Email $draft, StructuredAgentResponse $response): void
    {
        $document = HTMLDocument::createFromString('<!doctype html><html><body>'.($draft->body->body_html ?? '').'</body></html>', LIBXML_NOERROR);
        $signature = $document->querySelector('[data-type="customBlock"][data-id="signature"]');
        $html = Str::markdown((string) $response['body_markdown'], ['html_input' => 'strip', 'allow_unsafe_links' => false]).($signature instanceof Element ? $document->saveHtml($signature) : '');
        $recipients = fn (EmailParticipantRole $role): array => array_values($draft->participants->where('role', $role)->pluck('email_address')->all());
        $sourceId = $draft->in_reply_to === null ? null : Email::query()->forWorkspace($this->workspaceId)->where('rfc_message_id', $draft->in_reply_to)->value('id');
        resolve(SaveEmailDraftAction::class)->execute($user, [
            'connected_account_id' => $draft->connected_account_id,
            'subject' => filled($draft->subject) ? $draft->subject : mb_substr((string) $response['subject'], 0, 255),
            'body_html' => $html,
            'to' => $recipients(EmailParticipantRole::TO), 'cc' => $recipients(EmailParticipantRole::CC), 'bcc' => $recipients(EmailParticipantRole::BCC),
            'source_email_id' => $sourceId,
            'creation_source' => $draft->creation_source,
        ], $this->draftId, $this->draftVersion);
    }
}
