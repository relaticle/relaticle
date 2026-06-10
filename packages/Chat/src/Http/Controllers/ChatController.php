<?php

declare(strict_types=1);

namespace Relaticle\Chat\Http\Controllers;

use App\Enums\Plan;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Relaticle\Chat\Actions\DeleteConversation;
use Relaticle\Chat\Actions\ListConversations;
use Relaticle\Chat\Actions\RenameConversation;
use Relaticle\Chat\Enums\AiModel;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\AiModelResolver;
use Relaticle\Chat\Services\ApprovalContinuationService;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Services\TipTapDocumentParser;
use Relaticle\Chat\Support\LikePattern;
use Relaticle\Chat\Support\TitleSanitizer;

final readonly class ChatController
{
    public function __construct(
        private CreditService $creditService,
        private AiModelResolver $modelResolver,
        private TipTapDocumentParser $documentParser,
    ) {}

    public function send(Request $request, ?string $conversation = null): JsonResponse
    {
        $validated = $request->validate([
            'document' => ['required', 'array'],
            'model' => ['nullable', 'string', Rule::enum(AiModel::class)],
            'conversation_id' => ['nullable', 'string', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam;

        $parsed = $this->documentParser->parse($validated['document'], $team);

        if ($parsed['text'] === '') {
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
            $existing->user_id !== (string) $user->getKey()
                || ($existing->team_id !== null && $existing->team_id !== $team->getKey()),
            403
        );

        if (filled($validated['model'] ?? null) && $validated['model'] !== AiModel::Auto->value) {
            $requestedModel = AiModel::from($validated['model']);

            if (! $team->plan->allowsModel($requestedModel)) {
                $isFree = $team->plan === Plan::Free;

                return response()->json([
                    'error' => 'model_not_allowed',
                    'message' => "The {$requestedModel->label()} model is not available on the {$team->plan->label()} plan.",
                    'plan' => $team->plan->value,
                    'requested_model' => $requestedModel->value,
                    'upgrade_available' => $isFree,
                    'upgrade_url' => $isFree ? url('/app/billing') : null,
                ], 403);
            }
        }

        if (! $this->creditService->reserveCredit($team)) {
            $balance = AiCreditBalance::query()
                ->where('team_id', $team->getKey())
                ->first();

            $isFree = $team->plan === Plan::Free;

            return response()->json([
                'error' => 'credits_exhausted',
                'message' => "You have used all {$team->plan->credits()} credits for this {$team->plan->label()} plan period.",
                'plan' => $team->plan->value,
                'allowance' => $team->plan->credits(),
                'reset_at' => $balance?->period_ends_at?->toIso8601String(),
                'upgrade_available' => $isFree,
                'upgrade_url' => $isFree ? url('/app/billing') : null,
            ], 402);
        }

        DB::transaction(function () use ($conversation, $user, $team): void {
            $row = DB::table('agent_conversations')
                ->where('id', $conversation)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return;
            }

            abort_if($row->user_id !== (string) $user->getKey(), 403);

            if ($row->team_id !== null) {
                return;
            }

            DB::table('agent_conversations')
                ->where('id', $conversation)
                ->update(['team_id' => $team->getKey(), 'updated_at' => now()]);
        });

        $resolved = $this->modelResolver->resolve($user, $validated['model'] ?? null);

        dispatch(new ProcessChatMessage(
            user: $user,
            team: $team,
            message: $parsed['text'],
            conversationId: $conversation,
            resolved: $resolved,
            mentions: $parsed['mentions'],
            document: $validated['document'],
            turnId: (string) Str::ulid(),
        ));

        return response()->json([
            'status' => 'processing',
            'conversation_id' => $conversation,
        ]);
    }

    public function createConversation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document' => ['required', 'array'],
            'model' => ['nullable', 'string', Rule::enum(AiModel::class)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam;

        abort_if($team === null, 403);

        $parsed = $this->documentParser->parse($validated['document'], $team);

        if ($parsed['text'] === '') {
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

        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => (string) $user->getKey(),
            'team_id' => $team->getKey(),
            'title' => TitleSanitizer::clean($parsed['text']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['conversation_id' => $conversationId]);
    }

    public function cancel(Request $request, string $conversationId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam;

        $row = DB::table('agent_conversations')->where('id', $conversationId)->first();

        abort_if($row === null, 404);
        abort_if(
            $row->user_id !== (string) $user->getKey()
                || ($row->team_id !== null && $row->team_id !== $team->getKey()),
            404,
        );

        Cache::put(
            "chat:cancel:{$conversationId}",
            (string) $user->getKey(),
            now()->addMinutes(5),
        );

        return response()->json(['cancelled' => true]);
    }

    public function resume(Request $request, string $conversationId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $conversation = DB::table('agent_conversations')->where('id', $conversationId)->first();

        abort_if($conversation === null, 404);
        abort_if(
            $conversation->user_id !== (string) $user->getKey()
                || ($conversation->team_id !== null && $conversation->team_id !== $user->currentTeam->getKey()),
            403,
        );

        $action = resolve(PendingActionService::class)->latestUnjournaledResolvedAction($conversationId);

        if (! $action instanceof PendingAction) {
            return response()->json(['error' => 'Nothing to resume — send a new message instead.'], 409);
        }

        if ($action->user_id !== $user->getKey()) {
            return response()->json(['error' => 'Nothing to resume — send a new message instead.'], 409);
        }

        if (! Cache::add("chat:resume:{$conversationId}", 1, 30)) {
            return response()->json(['error' => 'A resume is already in progress.'], 409);
        }

        resolve(ApprovalContinuationService::class)->dispatchContinuation($action, $action->status->value);

        return response()->json(['status' => 'resuming']);
    }

    public function mentions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $search = LikePattern::escape($validated['q']);
        $limit = 5;

        /** @var User $user */
        $user = $request->user();
        $team = $user->currentTeam;

        $results = collect();

        $results = $results->merge(
            People::query()
                ->whereBelongsTo($team)
                ->where('name', 'ilike', "%{$search}%")
                ->orderByRaw('CASE WHEN name ilike ? THEN 0 ELSE 1 END', ["{$search}%"])
                ->orderByRaw('LENGTH(name) ASC')
                ->orderBy('name')
                ->limit($limit)
                ->with('team')
                ->get(['id', 'name', 'team_id'])
                ->filter(fn (People $r): bool => $user->can('view', $r))
                ->values()
                ->map(fn (People $r): array => ['id' => $r->id, 'name' => $r->name, 'type' => 'people'])
        );

        $results = $results->merge(
            Company::query()
                ->whereBelongsTo($team)
                ->where('name', 'ilike', "%{$search}%")
                ->orderByRaw('CASE WHEN name ilike ? THEN 0 ELSE 1 END', ["{$search}%"])
                ->orderByRaw('LENGTH(name) ASC')
                ->orderBy('name')
                ->limit($limit)
                ->with('team')
                ->get(['id', 'name', 'team_id'])
                ->filter(fn (Company $r): bool => $user->can('view', $r))
                ->values()
                ->map(fn (Company $r): array => ['id' => $r->id, 'name' => $r->name, 'type' => 'company'])
        );

        $results = $results->merge(
            Opportunity::query()
                ->whereBelongsTo($team)
                ->where('name', 'ilike', "%{$search}%")
                ->orderByRaw('CASE WHEN name ilike ? THEN 0 ELSE 1 END', ["{$search}%"])
                ->orderByRaw('LENGTH(name) ASC')
                ->orderBy('name')
                ->limit($limit)
                ->with('team')
                ->get(['id', 'name', 'team_id'])
                ->filter(fn (Opportunity $r): bool => $user->can('view', $r))
                ->values()
                ->map(fn (Opportunity $r): array => ['id' => $r->id, 'name' => $r->name, 'type' => 'opportunity'])
        );

        $results = $results->merge(
            Task::query()
                ->whereBelongsTo($team)
                ->where('title', 'ilike', "%{$search}%")
                ->orderByRaw('CASE WHEN title ilike ? THEN 0 ELSE 1 END', ["{$search}%"])
                ->orderByRaw('LENGTH(title) ASC')
                ->orderBy('title')
                ->limit($limit)
                ->with('team')
                ->get(['id', 'title', 'team_id'])
                ->filter(fn (Task $r): bool => $user->can('view', $r))
                ->values()
                ->map(fn (Task $r): array => ['id' => $r->id, 'name' => $r->title, 'type' => 'task'])
        );

        $results = $results->merge(
            Note::query()
                ->whereBelongsTo($team)
                ->where('title', 'ilike', "%{$search}%")
                ->orderByRaw('CASE WHEN title ilike ? THEN 0 ELSE 1 END', ["{$search}%"])
                ->orderByRaw('LENGTH(title) ASC')
                ->orderBy('title')
                ->limit($limit)
                ->with('team')
                ->get(['id', 'title', 'team_id'])
                ->filter(fn (Note $r): bool => $user->can('view', $r))
                ->values()
                ->map(fn (Note $r): array => ['id' => $r->id, 'name' => $r->title, 'type' => 'note'])
        );

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
