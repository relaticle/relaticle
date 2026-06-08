<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tool Call Credit Bonus
    |--------------------------------------------------------------------------
    */

    'tool_call_credit_bonus' => 0.5,

    /*
    |--------------------------------------------------------------------------
    | Pending Action Expiry (minutes)
    |--------------------------------------------------------------------------
    */

    'pending_action_expiry_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | Conversation Context Window
    |--------------------------------------------------------------------------
    |
    | Maximum number of past conversation messages (user + assistant + tool
    | results) sent to the model on each request. Lower values reduce token
    | usage; higher values give the model more memory of earlier turns.
    */

    'max_conversation_messages' => (int) env('CHAT_MAX_CONVERSATION_MESSAGES', 100),

];
