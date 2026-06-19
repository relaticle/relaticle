<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Batch Write Cap
    |--------------------------------------------------------------------------
    |
    | Maximum number of records that may be created or deleted in a single
    | tool call. Enforced server-side in the tool layer (never via prompt text).
    | Override with CHAT_MAX_BATCH_SIZE in .env for local testing.
    */

    'max_batch_size' => (int) env('CHAT_MAX_BATCH_SIZE', 25),

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

    /*
    |--------------------------------------------------------------------------
    | Anthropic Prompt Caching
    |--------------------------------------------------------------------------
    |
    | Marks the static system prompt with a cache_control breakpoint, which
    | caches the whole request prefix (all tool schemas + instructions) on
    | Anthropic's side. Cuts per-turn input tokens dramatically for multi-turn
    | conversations. Disable if a model/provider combination misbehaves.
    */

    'anthropic_prompt_caching' => (bool) env('CHAT_ANTHROPIC_PROMPT_CACHING', true),

    /*
    |--------------------------------------------------------------------------
    | Provider Stream-Start Rate (per second, per provider)
    |--------------------------------------------------------------------------
    |
    | Caps how many chat streams may START per second against one provider so
    | a retry storm from one tenant cannot stampede the provider and drag every
    | other conversation into 429 backoff with it.
    */

    'provider_starts_per_second' => (int) env('CHAT_PROVIDER_STARTS_PER_SECOND', 8),

];
