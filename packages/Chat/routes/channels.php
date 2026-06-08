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

    return $row->user_id === (string) $user->getKey()
        && ($row->team_id === null || $row->team_id === $user->current_team_id);
});
