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

    /*
    |--------------------------------------------------------------------------
    | Custom Field Schema Caps
    |--------------------------------------------------------------------------
    |
    | Maximum number of custom fields per entity type (across all entities of
    | that type for a tenant) and maximum options per choice field. Enforced
    | server-side in the action layer — never via prompt text only.
    */

    'max_custom_fields_per_entity' => (int) env('CHAT_MAX_CUSTOM_FIELDS_PER_ENTITY', 50),

    'max_field_options' => (int) env('CHAT_MAX_FIELD_OPTIONS', 50),

    /*
    |--------------------------------------------------------------------------
    | Chat Model Registry
    |--------------------------------------------------------------------------
    |
    | The user-facing model catalog. Each entry references a provider defined in
    | config/ai.php. `auto` is synthetic (handled by the resolver) and is not
    | listed here. Self-hosted custom models are merged in from SELF_HOSTED_AI_*
    | env at boot. `supports_tools:false` hides a model (Gemini, until laravel/ai
    | supports tool_config). `write_guard`: api = provider enforces one write per
    | turn; prompt = relies on the prompt + the approval gate.
    */

    'self_hosted' => [
        'url' => env('SELF_HOSTED_AI_URL'),
        'key' => env('SELF_HOSTED_AI_KEY', ''),
        'models' => env('SELF_HOSTED_AI_MODELS'),
    ],

    'auto_chain' => ['claude-sonnet', 'gpt-5-5', 'ollama'],

    'models' => [
        ['id' => 'claude-sonnet', 'label' => 'Sonnet 4.6', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'min_plan' => 'free', 'credit_multiplier' => 1.0, 'supports_tools' => true, 'write_guard' => 'api', 'self_hosted' => false],
        ['id' => 'claude-opus', 'label' => 'Opus 4.7', 'provider' => 'anthropic', 'model' => 'claude-opus-4-7', 'min_plan' => 'pro', 'credit_multiplier' => 3.0, 'supports_tools' => true, 'write_guard' => 'api', 'self_hosted' => false],
        ['id' => 'gpt-5-5', 'label' => 'GPT 5.5', 'provider' => 'openai', 'model' => 'gpt-5.5', 'min_plan' => 'pro', 'credit_multiplier' => 1.5, 'supports_tools' => true, 'write_guard' => 'api', 'self_hosted' => false],
        ['id' => 'gpt-5-4', 'label' => 'GPT 5.4', 'provider' => 'openai', 'model' => 'gpt-5.4', 'min_plan' => 'pro', 'credit_multiplier' => 1.5, 'supports_tools' => true, 'write_guard' => 'api', 'self_hosted' => false],
        ['id' => 'gemini-3-flash', 'label' => 'Gemini 3 Flash', 'provider' => 'gemini', 'model' => 'gemini-3-flash', 'min_plan' => 'free', 'credit_multiplier' => 1.0, 'supports_tools' => false, 'write_guard' => 'prompt', 'self_hosted' => false],
        ['id' => 'gemini-3-1-pro', 'label' => 'Gemini 3.1 Pro', 'provider' => 'gemini', 'model' => 'gemini-3.1-pro', 'min_plan' => 'pro', 'credit_multiplier' => 1.5, 'supports_tools' => false, 'write_guard' => 'prompt', 'self_hosted' => false],
        ['id' => 'ollama', 'label' => 'Ollama', 'provider' => 'ollama', 'model' => env('OLLAMA_MODEL'), 'min_plan' => 'free', 'credit_multiplier' => 1.0, 'supports_tools' => true, 'write_guard' => 'prompt', 'self_hosted' => true],
    ],

];
