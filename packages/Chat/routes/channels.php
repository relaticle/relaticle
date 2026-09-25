<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

Broadcast::channel('chat.conversation.{conversationId}', function (User $user, string $conversationId): bool {
    if (! Str::isUuid($conversationId)) {
        return false;
    }

    $row = DB::table('agent_conversations')->where('id', $conversationId)->first();

    if ($row === null) {
        return false;
    }

    return $row->participant_type === $user->getMorphClass()
        && $row->participant_id === (string) $user->getKey()
        && ($row->workspace_id === null || $row->workspace_id === $user->current_workspace_id);
});
