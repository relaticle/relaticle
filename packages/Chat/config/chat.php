<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Assistant Name
    |--------------------------------------------------------------------------
    |
    | The name the assistant introduces itself by, in the system prompt and
    | across the chat UI. Override with CHAT_ASSISTANT_NAME in .env.
    */

    'assistant_name' => env('CHAT_ASSISTANT_NAME', 'Rela'),

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
    | Max Plan Steps
    |--------------------------------------------------------------------------
    |
    | Maximum number of write tool calls one turn may chain into a single plan
    | proposal. Enforced server-side in the tool layer, and stated in the prompt
    | so the assistant splits a longer request instead of being cut off.
    */

    'max_plan_steps' => (int) env('CHAT_MAX_PLAN_STEPS', 6),

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

    'pending_action_expiry_minutes' => 1440,

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
    | Places two cache_control breakpoints on every Anthropic request. One marks
    | the static system prompt, caching the whole prefix (tool schemas plus
    | instructions) so a new conversation never rewrites it. The other is
    | Anthropic's automatic caching, which follows the last block of the request,
    | so each step of the agent loop reads the transcript the previous step
    | wrote. Cuts per-turn input tokens dramatically. Disable if a
    | model/provider combination misbehaves.
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
    | Conversation Title Generation
    |--------------------------------------------------------------------------
    |
    | A new conversation is stored under its opening message, truncated. Once
    | the first turn is dispatched, a queued job replaces that with a short
    | model-written title and broadcasts it to the open chat page. Switch this
    | off to keep the truncated message as the permanent title.
    |
    | The title runs on the cheapest model of whichever provider served the
    | turn — override that per provider with
    | `ai.providers.<name>.models.text.cheapest`.
    */

    'title_generation' => [
        'enabled' => (bool) env('CHAT_TITLE_GENERATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Voice Input
    |--------------------------------------------------------------------------
    |
    | Push-to-talk dictation in the composer. A recording is transcribed by the
    | provider named in `ai.default_for_transcription` and the text is inserted
    | at the cursor for the user to review. It is never sent on their behalf.
    | The mic button additionally requires that provider to have a key, so an
    | install without one shows no button at all (ModelRegistry::voiceInputAvailable()).
    */

    'voice_enabled' => (bool) env('CHAT_VOICE_ENABLED', true),

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

    /*
    |--------------------------------------------------------------------------
    | Model Cost Rates (USD per million tokens)
    |--------------------------------------------------------------------------
    |
    | Keys must match the `model` values above and any historical model strings
    | in ai_credit_transactions. Models without an entry are surfaced as
    | "unpriced" in the sysadmin cost widget — never silently zero.
    |
    | Vendor list prices, short-context tier. Long-context requests bill higher
    | (gpt-5.x above 272k input, gemini-3.1-pro above 200k), so the widget's
    | figure is an estimate rather than an invoice. Re-check when a vendor
    | changes its price list. Ollama is self-hosted (no per-token API cost) and
    | its model id is env-driven, so it has no entry.
    */

    'model_costs' => [
        'claude-sonnet-4-6' => ['input_per_mtok' => 3.00, 'output_per_mtok' => 15.00],
        'claude-opus-4-7' => ['input_per_mtok' => 5.00, 'output_per_mtok' => 25.00],
        'gpt-5.5' => ['input_per_mtok' => 5.00, 'output_per_mtok' => 30.00],
        'gpt-5.4' => ['input_per_mtok' => 2.50, 'output_per_mtok' => 15.00],
        'gemini-3-flash' => ['input_per_mtok' => 0.50, 'output_per_mtok' => 3.00],
        'gemini-3.1-pro' => ['input_per_mtok' => 2.00, 'output_per_mtok' => 12.00],
    ],

];
