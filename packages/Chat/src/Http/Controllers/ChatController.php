<?php

declare(strict_types=1);

namespace Relaticle\Chat\Http\Controllers;

use App\Enums\Plan;
use App\Features\Billing;
use App\Filament\Pages\Billing as BillingPage;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\CreditPackCatalog;
use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Actions\ConsumeChatAttachment;
use Relaticle\Chat\Actions\DeleteConversation;
use Relaticle\Chat\Actions\ListConversations;
use Relaticle\Chat\Actions\RenameConversation;
use Relaticle\Chat\Actions\StoreImportHandoff;
use Relaticle\Chat\Jobs\GenerateConversationTitle;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\AiModelResolver;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\Chat\Services\TipTapDocumentParser;
use Relaticle\Chat\Support\AttachedRows;
use Relaticle\Chat\Support\ChatAttachment;
use Relaticle\Chat\Support\ConversationTitleGate;
use Relaticle\Chat\Support\ModelDescriptor;
use Relaticle\Chat\Support\RecordReferenceResolver;
use Relaticle\Chat\Support\TitleSanitizer;
use Relaticle\Chat\Support\TranscriptScope;
use Relaticle\Chat\Support\TurnPresence;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class ChatController
{
    /**
     * Cap on in-conversation search hits. Deliberately small: the overlay is a
     * jump-to affordance, not a results page.
     */
    private const int SEARCH_MATCH_LIMIT = 20;

    public function __construct(
        private CreditService $creditService,
        private AiModelResolver $modelResolver,
        private ModelRegistry $registry,
        private TipTapDocumentParser $documentParser,
        private StoreImportHandoff $importHandoffs,
        private ConsumeChatAttachment $consumeAttachment,
    ) {}

    /** @return list<string> */
    private function modelIds(): array
    {
        return ['auto', ...array_map(static fn (ModelDescriptor $m): string => $m->id, $this->registry->all())];
    }

    /**
     * Billing page URL for the workspace, or null when billing is switched off.
     * Resolved through the panel route so it stays correct whether the app panel
     * is served from a path prefix or its own subdomain.
     */
    private function billingUrl(Workspace $workspace): ?string
    {
        if (! Feature::active(Billing::class)) {
            return null;
        }

        return BillingPage::getUrl(panel: 'app', tenant: $workspace);
    }

    public function send(Request $request, ?string $conversation = null): JsonResponse
    {
        $validated = $request->validate([
            'document' => ['required', 'array'],
            'model' => ['nullable', 'string', Rule::in($this->modelIds())],
            'conversation_id' => ['nullable', 'string', 'uuid'],
            'attachment_id' => ['nullable', 'string', 'uuid'],
            'page_context' => ['nullable', 'array'],
            'page_context.type' => ['required_with:page_context', 'string', 'max:32'],
            'page_context.id' => ['required_with:page_context', 'string', 'max:26'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        $parsed = $this->documentParser->parse($validated['document'], $workspace);
        $attachment = $this->resolveAttachment($validated['attachment_id'] ?? null, $user, $workspace);

        if ($parsed['text'] === '' && ! $attachment instanceof ChatAttachment) {
            throw ValidationException::withMessages([
                'document' => 'Message is empty.',
            ]);
        }

        if (mb_strlen($parsed['text']) > 5000) {
            throw ValidationException::withMessages([
                'document' => 'Message is too long.',
            ]);
        }

        $conversation ??= $validated['conversation_id'] ?? null;

        abort_if($conversation === null, 422, 'conversation_id is required.');

        $existing = DB::table('agent_conversations')->where('id', $conversation)->first();

        abort_if($existing === null, 404);

        abort_if(
            $existing->participant_type !== $user->getMorphClass()
                || $existing->participant_id !== (string) $user->getKey()
                || ($existing->workspace_id !== null && $existing->workspace_id !== $workspace->getKey()),
            403
        );

        if (filled($validated['model'] ?? null) && $validated['model'] !== 'auto') {
            $descriptor = $this->registry->find($validated['model']);

            if ($descriptor instanceof ModelDescriptor && ! $descriptor->allowedForPlan($workspace->plan)) {
                $isFree = $workspace->plan === Plan::Free;

                return response()->json([
                    'error' => 'model_not_allowed',
                    'message' => __(':model is not available on the :plan plan.', ['model' => $descriptor->label, 'plan' => $workspace->plan->getLabel()]),
                    'plan' => $workspace->plan->value,
                    'requested_model' => $descriptor->id,
                    'upgrade_available' => $isFree,
                    'upgrade_url' => $isFree ? $this->billingUrl($workspace) : null,
                ], 403);
            }
        }

        if ($attachment instanceof ChatAttachment && $attachment->rowCount() > AttachedRows::INLINE_ROW_LIMIT) {
            $stored = $this->importHandoffs->execute($user, $workspace, $conversation, $attachment, $parsed['text'], $validated['document']);

            return response()->json([
                'status' => 'stored',
                'conversation_id' => $conversation,
                ...$stored,
            ]);
        }

        $message = $attachment instanceof ChatAttachment
            ? AttachedRows::append($parsed['text'], $attachment)
            : $parsed['text'];

        $turnId = (string) Str::ulid();

        if (! $this->creditService->reserveCredit($workspace, reservationKey: "reserve-{$turnId}", conversationId: $conversation, userId: (string) $user->getKey())) {
            $balance = AiCreditBalance::query()
                ->where('workspace_id', $workspace->getKey())
                ->first();

            $isFree = $workspace->plan === Plan::Free;
            $canTopUp = ! $isFree && resolve(CreditPackCatalog::class)->hasPurchasable();
            // Not $workspace->plan->credits(): a past-due workspace refills at the
            // Free allowance, so the plan's figure would name credits it never got.
            $allowance = $this->creditService->allowanceFor($workspace);

            return response()->json([
                'error' => 'credits_exhausted',
                'message' => "You have used all {$allowance} credits for this {$workspace->plan->getLabel()} plan period.",
                'plan' => $workspace->plan->value,
                'allowance' => $allowance,
                'reset_at' => $balance?->period_ends_at?->toIso8601String(),
                'upgrade_available' => $isFree,
                'upgrade_url' => $isFree ? $this->billingUrl($workspace) : null,
                // A top-up is only offered when a pack can actually be bought;
                // otherwise the CTA lands on a billing page with nothing to buy.
                'top_up_available' => $canTopUp,
                'top_up_url' => $canTopUp ? $this->billingUrl($workspace) : null,
            ], 402);
        }

        DB::transaction(function () use ($conversation, $user, $workspace): void {
            $row = DB::table('agent_conversations')
                ->where('id', $conversation)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return;
            }

            abort_if(
                $row->participant_type !== $user->getMorphClass()
                    || $row->participant_id !== (string) $user->getKey(),
                403
            );

            if ($row->workspace_id !== null) {
                return;
            }

            DB::table('agent_conversations')
                ->where('id', $conversation)
                ->update(['workspace_id' => $workspace->getKey(), 'updated_at' => now()]);
        });

        $resolved = $this->modelResolver->resolve($user, $validated['model'] ?? null);
        $pageContext = $this->resolvePageContext($validated['page_context'] ?? null, $user);

        $this->maybeTitleConversation(
            $conversation,
            $attachment instanceof ChatAttachment && $parsed['text'] === '' ? $attachment->name() : $parsed['text'],
            $resolved['provider'],
            $pageContext,
        );

        TurnPresence::begin(
            $conversation,
            turnId: $turnId,
            message: $parsed['text'],
            document: $validated['document'],
            mentions: $parsed['mentions'],
            pageContext: $pageContext,
        );

        if ($attachment instanceof ChatAttachment) {
            $this->consumeAttachment->execute($attachment, $conversation);
        }

        dispatch(new ProcessChatMessage(
            user: $user,
            workspace: $workspace,
            message: $message,
            conversationId: $conversation,
            resolved: $resolved,
            mentions: $parsed['mentions'],
            document: $validated['document'],
            pageContext: $pageContext,
            turnId: $turnId,
            attachment: $attachment?->meta(),
        ));

        return response()->json([
            'status' => 'processing',
            'conversation_id' => $conversation,
        ]);
    }

    private function resolveAttachment(?string $attachmentId, User $user, Workspace $workspace): ?ChatAttachment
    {
        if ($attachmentId === null) {
            return null;
        }

        $media = ChatAttachment::query($workspace, $user)
            ->where('uuid', $attachmentId)
            ->whereNull('custom_properties->consumed_at')
            ->first();

        $attachment = $media instanceof Media ? new ChatAttachment($media) : null;

        if ($attachment === null || ! $attachment->fileExists()) {
            throw ValidationException::withMessages([
                'attachment_id' => __('That attachment is no longer available. Attach the file again.'),
            ]);
        }

        return $attachment;
    }

    /**
     * Title the conversation from the message that just arrived, racing the turn
     * rather than waiting for it. A chat that streams for a minute should not sit
     * in the sidebar under a truncated sentence for that whole minute.
     *
     * ConversationTitleGate decides whether there is anything to do: it returns
     * the provisional title (the opening message, sanitized) only while the
     * stored title still IS that, and only for the conversation's first few typed
     * messages. That single condition carries two rules, a chat the user has
     * named is never re-titled, and a chat whose opener carried no topic stays
     * eligible, so the next few messages get a chance to name it instead of it
     * being stuck on "hey" forever.
     *
     * @param  array{type: string, id: string, label: string}|null  $pageContext
     */
    private function maybeTitleConversation(string $conversationId, string $message, ?string $provider, ?array $pageContext): void
    {
        $provisional = ConversationTitleGate::beforeTurn($conversationId, $message);

        if ($provisional === null) {
            return;
        }

        dispatch(new GenerateConversationTitle(
            conversationId: $conversationId,
            provisionalTitle: $provisional,
            message: $message,
            provider: $provider,
            pageContext: $pageContext,
        ));
    }

    /**
     * Resolve the record the user was viewing when they sent the message.
     *
     * The client payload is untrusted: it names a type and id, and nothing
     * more. Both are re-resolved here under workspace scope and the view policy,
     * exactly as BaseReadShowTool does, so a forged id for another workspace's
     * record yields null rather than leaking a label.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{type: string, id: string, label: string}|null
     */
    private function resolvePageContext(?array $payload, User $user): ?array
    {
        if ($payload === null) {
            return null;
        }

        $type = $payload['type'] ?? null;
        $id = $payload['id'] ?? null;

        if (! is_string($type) || ! is_string($id) || $type === '' || $id === '') {
            return null;
        }

        /** @var class-string<Model>|null $modelClass */
        $modelClass = in_array($type, RecordReferenceResolver::CHIP_TYPES, true)
            ? Relation::getMorphedModel($type)
            : null;

        if ($modelClass === null) {
            return null;
        }

        $record = $modelClass::query()
            ->whereBelongsTo($user->currentWorkspace)
            ->whereKey($id)
            ->first();

        if ($record === null || $user->cannot('view', $record)) {
            return null;
        }

        $label = $record->getAttribute('name') ?? $record->getAttribute('title');

        return [
            'type' => $type,
            'id' => (string) $record->getKey(),
            'label' => is_string($label) ? $label : '(unnamed)',
        ];
    }

    public function createConversation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document' => ['required', 'array'],
            'model' => ['nullable', 'string', Rule::in($this->modelIds())],
            'attachment_id' => ['nullable', 'string', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        abort_if($workspace === null, 403);

        $parsed = $this->documentParser->parse($validated['document'], $workspace);
        $attachment = $this->resolveAttachment($validated['attachment_id'] ?? null, $user, $workspace);

        if ($parsed['text'] === '' && ! $attachment instanceof ChatAttachment) {
            throw ValidationException::withMessages([
                'document' => 'Message is empty.',
            ]);
        }

        if (mb_strlen($parsed['text']) > 5000) {
            throw ValidationException::withMessages([
                'document' => 'Message is too long.',
            ]);
        }

        $conversationId = (string) Str::uuid7();

        $title = $attachment instanceof ChatAttachment && $parsed['text'] === ''
            ? $attachment->name()
            : $parsed['text'];

        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => (string) $user->getKey(),
            'workspace_id' => $workspace->getKey(),
            'title' => TitleSanitizer::clean($title),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['conversation_id' => $conversationId]);
    }

    public function cancel(Request $request, string $conversationId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        $row = DB::table('agent_conversations')->where('id', $conversationId)->first();

        abort_if($row === null, 404);
        abort_if(
            $row->participant_type !== $user->getMorphClass()
                || $row->participant_id !== (string) $user->getKey()
                || ($row->workspace_id !== null && $row->workspace_id !== $workspace->getKey()),
            404,
        );

        Cache::put(
            "chat:cancel:{$conversationId}",
            (string) $user->getKey(),
            now()->addMinutes(5),
        );

        return response()->json(['cancelled' => true]);
    }

    /**
     * Mark a turn (and everything after it) superseded. This is the server-truth side
     * of Regenerate/Edit. Without this the client splice is a lie: reload
     * resurrects the replaced turns and the model keeps them in its history.
     *
     * anchor_id targets a persisted user message; when the client only has an
     * optimistic (not yet persisted) message it sends anchor_content instead,
     * which must match the latest user row. A mismatch means that row belongs
     * to an OLDER turn (the optimistic one never persisted), and superseding it
     * would hide a good turn, so we refuse and supersede nothing.
     */
    public function supersedeMessages(Request $request, string $conversationId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'anchor_id' => ['nullable', 'string', 'max:36'],
            'anchor_content' => ['nullable', 'string', 'max:5000'],
        ]);

        $conversation = DB::table('agent_conversations')->where('id', $conversationId)->first();

        abort_if(
            $conversation === null
                || $conversation->participant_type !== $user->getMorphClass()
                || $conversation->participant_id !== (string) $user->getKey()
                || ($conversation->workspace_id !== null && $conversation->workspace_id !== $user->currentWorkspace->getKey()),
            404,
        );

        $anchorId = $validated['anchor_id'] ?? null;

        if ($anchorId !== null) {
            $anchor = DB::table('agent_conversation_messages')
                ->where('conversation_id', $conversationId)
                ->where('id', $anchorId)
                ->first();

            abort_if($anchor === null, 404);
            abort_if((string) $anchor->role !== 'user', 422, 'Only user messages can anchor a supersede.');
        } else {
            $anchor = DB::table('agent_conversation_messages')
                ->where('conversation_id', $conversationId)
                ->where('role', 'user')
                ->whereNull('superseded_at')
                ->orderByDesc('id')
                ->first();

            if ($anchor === null) {
                return response()->json(['superseded' => 0]);
            }

            $expected = trim((string) ($validated['anchor_content'] ?? ''));

            if ($expected !== '' && trim((string) $anchor->content) !== $expected) {
                return response()->json(['superseded' => 0]);
            }
        }

        $superseded = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('id', '>=', (string) $anchor->id)
            ->whereNull('superseded_at')
            ->update(['superseded_at' => now(), 'updated_at' => now()]);

        return response()->json(['superseded' => $superseded]);
    }

    /**
     * Search within one conversation.
     *
     * Scoped through TranscriptScope, the same predicate set the pager applies,
     * so every id returned here is one the transcript can actually reach. A hit
     * the pager could never render would send the client's load-until-found
     * loop all the way to the top of the history and then report nothing.
     *
     * `q` is a user-supplied pattern, so it goes through LikePattern::escape
     * before the ILIKE: a literal `%` or `_` typed into the search box must
     * match that character, not act as a wildcard.
     */
    public function searchMessages(Request $request, string $conversationId): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $conversation = DB::table('agent_conversations')->where('id', $conversationId)->first();

        abort_if(
            $conversation === null
                || $conversation->participant_type !== $user->getMorphClass()
                || $conversation->participant_id !== (string) $user->getKey()
                || ($conversation->workspace_id !== null && $conversation->workspace_id !== $user->current_workspace_id),
            404,
        );

        $escaped = LikePattern::escape($validated['q']);

        $matches = TranscriptScope::apply(
            DB::table('agent_conversation_messages as m'),
            $user,
            $conversationId,
        )
            ->where('m.content', 'ilike', "%{$escaped}%")
            ->orderByDesc('m.id')
            ->limit(self::SEARCH_MATCH_LIMIT)
            ->get(['m.id', 'm.content']);

        return response()->json([
            'matches' => $matches
                ->map(fn (object $row): array => [
                    'message_id' => (string) $row->id,
                    'snippet' => $this->snippet((string) $row->content, $validated['q']),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * A one-line excerpt centred on the match, for the result row in the search
     * overlay. Markdown syntax is stripped and whitespace collapsed so a
     * multi-paragraph assistant answer renders as one readable line instead of
     * leaking **bold** markers and [label](/r/...) link syntax.
     */
    private function snippet(string $content, string $needle): string
    {
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $content) ?? $content;
        $text = preg_replace('/[*_`~#]+/', '', $text) ?? $text;
        $text = Str::squish($text);

        return Str::excerpt($text, Str::squish($needle), ['radius' => 60, 'omission' => '…'])
            ?? Str::limit($text, 160, '…');
    }

    public function mentions(Request $request, RecordReferenceResolver $resolver): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $search = LikePattern::escape($validated['q']);
        $limit = 5;

        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        $results = collect();

        foreach ([
            [People::class, 'name', 'people'],
            [Company::class, 'name', 'company'],
            [Opportunity::class, 'name', 'opportunity'],
            [Task::class, 'title', 'task'],
            [Note::class, 'title', 'note'],
        ] as [$modelClass, $column, $type]) {
            $results = $results->merge(
                $modelClass::query()
                    ->whereBelongsTo($workspace)
                    ->where($column, 'ilike', "%{$search}%")
                    ->orderByRaw("CASE WHEN {$column} ilike ? THEN 0 ELSE 1 END", ["{$search}%"])
                    ->orderByRaw("LENGTH({$column}) ASC")
                    ->orderBy($column)
                    ->limit($limit)
                    ->get(['id', $column, 'workspace_id'])
                    ->filter(fn (Model $r): bool => $user->can('view', $r))
                    ->values()
                    ->map(fn (Model $r): array => ['id' => $r->getKey(), 'name' => $r->getAttribute($column), 'type' => $type, 'url' => $resolver->urlFor($type, (string) $r->getKey())])
            );
        }

        return response()->json(['data' => $results->take(15)->values()]);
    }

    public function conversations(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => (new ListConversations)->execute($user),
        ]);
    }

    public function destroyConversation(Request $request, string $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! (new DeleteConversation)->execute($user, $conversation)) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        return response()->json(['success' => true]);
    }

    public function rename(Request $request, string $conversationId): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $title = (new RenameConversation)->execute(
                $user,
                $conversationId,
                $validated['title'],
            );
        } catch (\RuntimeException) {
            abort(404);
        }

        return response()->json([
            'title' => $title,
            'conversation_id' => $conversationId,
        ]);
    }
}
