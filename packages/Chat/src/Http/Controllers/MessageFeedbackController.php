<?php

declare(strict_types=1);

namespace Relaticle\Chat\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Relaticle\Chat\Models\ChatMessageFeedback;
use Relaticle\Chat\Support\ChatTelemetry;

final class MessageFeedbackController
{
    /**
     * Upsert the current user's rating of an assistant message. A second POST
     * with the same rating refreshes category/comment; switching rating
     * replaces it — one row per (user, message) always.
     */
    public function store(Request $request, string $messageId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'rating' => ['required', 'string', Rule::in([ChatMessageFeedback::RATING_UP, ChatMessageFeedback::RATING_DOWN])],
            'category' => ['nullable', 'string', Rule::in(ChatMessageFeedback::CATEGORIES)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $message = $this->ownAssistantMessage($user, $messageId);

        abort_if(! $message instanceof \stdClass, 404);

        $meta = json_decode((string) ($message->meta ?? '{}'), true);

        $feedback = ChatMessageFeedback::query()->updateOrCreate(
            [
                'user_id' => $user->getKey(),
                'message_id' => $messageId,
            ],
            [
                'team_id' => $user->current_team_id,
                'conversation_id' => (string) $message->conversation_id,
                'rating' => $validated['rating'],
                'category' => $validated['category'] ?? null,
                'comment' => isset($validated['comment']) && trim((string) $validated['comment']) !== ''
                    ? trim((string) $validated['comment'])
                    : null,
                'model' => is_array($meta) && is_string($meta['model'] ?? null) ? $meta['model'] : null,
            ],
        );

        ChatTelemetry::breadcrumb('feedback.recorded', [
            'rating' => $feedback->rating,
            'category' => $feedback->category,
        ]);

        return response()->json([
            'rating' => $feedback->rating,
            'category' => $feedback->category,
            'comment' => $feedback->comment,
        ]);
    }

    /**
     * Retract the current user's rating (clicking the active thumb again).
     */
    public function destroy(Request $request, string $messageId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_if(! $this->ownAssistantMessage($user, $messageId) instanceof \stdClass, 404);

        ChatMessageFeedback::query()
            ->where('user_id', $user->getKey())
            ->where('message_id', $messageId)
            ->delete();

        return response()->json(['rating' => null]);
    }

    private function ownAssistantMessage(User $user, string $messageId): ?\stdClass
    {
        return DB::table('agent_conversation_messages as m')
            ->join('agent_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('m.id', $messageId)
            ->where('m.user_id', $user->getKey())
            ->where('c.team_id', $user->current_team_id)
            ->where('m.role', 'assistant')
            ->first(['m.id', 'm.conversation_id', 'm.meta']);
    }
}
