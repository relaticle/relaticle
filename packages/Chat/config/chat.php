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
    | Anthropic Reasoning Effort
    |--------------------------------------------------------------------------
    |
    | How deeply Anthropic thinks per turn: low, medium, high, xhigh or max.
    | Sampling parameters (temperature, top_p) were removed on Opus 4.7 and every
    | model since, and a request carrying one is rejected outright, so this is the
    | only quality-versus-cost dial those models expose. It is load-bearing rather
    | than cosmetic from Opus 5 onward, where thinking is on by default and a turn
    | spends output tokens before it writes a word. `high` is Anthropic's own
    | default; an unrecognised value falls back to it.
    */

    'anthropic_effort' => env('CHAT_ANTHROPIC_EFFORT', 'high'),

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
    | turn. Override that per provider with
    | `ai.providers.<name>.models.text.cheapest`.
    */

    'title_generation' => [
        'enabled' => (bool) env('CHAT_TITLE_GENERATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Next Steps
    |--------------------------------------------------------------------------
    |
    | After a turn ends, a second cheap model reads what the user asked and what
    | the assistant answered and drafts up to three things to do next. They sit
    | at the tail of the transcript as one-click prompts. Switch this off to
    | end the turn on the answer alone.
    |
    | Runs on the cheapest model of whichever provider served the turn, on the
    | default queue, and is never charged against the workspace's AI credits.
    */

    'next_steps' => [
        'enabled' => (bool) env('CHAT_NEXT_STEPS', true),
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
    | server-side in the action layer, never via prompt text only.
    */

    'max_custom_fields_per_entity' => (int) env('CHAT_MAX_CUSTOM_FIELDS_PER_ENTITY', 50),

    'max_field_options' => (int) env('CHAT_MAX_FIELD_OPTIONS', 50),

    /*
    |--------------------------------------------------------------------------
    | Chat Model Catalog
    |--------------------------------------------------------------------------
    |
    | The seed for the user-facing model catalog, copied into settings on first
    | migrate and editable from the sysadmin panel thereafter. Cloud models only:
    | self-hosted ones are merged in from SELF_HOSTED_AI_* / OLLAMA_* env at read
    | time, so `.env` stays their single source of truth.
    |
    | Per entry: `model` is the provider's own tag AND the entry's identity: the
    | value the picker stores, `?model=` carries and `ai_credit_transactions.model`
    | already records. It is unique across the catalog, and retagging a row makes
    | it a different model. `auto` plus list order is the Auto failover chain, so it
    | cannot name a model nobody offers. `capabilities` is measured by ModelProbe
    | against a real request, never typed. A disabled entry stays only to price
    | historical ai_credit_transactions rows that still name its model.
    |
    | Prices are USD per million tokens, vendor list price, short-context tier.
    | Long-context requests bill higher (gpt-5.x above 272k input, gemini-3.1-pro
    | above 200k), so the sysadmin figure is an estimate rather than an invoice.
    */

    'ollama' => [
        'model' => env('OLLAMA_MODEL'),
    ],

    'self_hosted' => [
        'url' => env('SELF_HOSTED_AI_URL'),
        'key' => env('SELF_HOSTED_AI_KEY', ''),
        'models' => env('SELF_HOSTED_AI_MODELS'),
    ],

    'models' => [
        ['label' => 'Sonnet 5', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'min_plan' => 'free', 'credit_multiplier' => 1.0, 'input_per_mtok' => 3.00, 'output_per_mtok' => 15.00, 'auto' => true, 'enabled' => true, 'capabilities' => ['supports_tools' => true, 'write_guard' => 'api'], 'verified_at' => null],
        ['label' => 'GPT 5.5', 'provider' => 'openai', 'model' => 'gpt-5.5', 'min_plan' => 'pro', 'credit_multiplier' => 1.5, 'input_per_mtok' => 5.00, 'output_per_mtok' => 30.00, 'auto' => true, 'enabled' => true, 'capabilities' => ['supports_tools' => true, 'write_guard' => 'api'], 'verified_at' => null],
        ['label' => 'Opus 5', 'provider' => 'anthropic', 'model' => 'claude-opus-5', 'min_plan' => 'pro', 'credit_multiplier' => 3.0, 'input_per_mtok' => 5.00, 'output_per_mtok' => 25.00, 'auto' => false, 'enabled' => true, 'capabilities' => ['supports_tools' => true, 'write_guard' => 'api'], 'verified_at' => null],
        ['label' => 'GPT 5.4', 'provider' => 'openai', 'model' => 'gpt-5.4', 'min_plan' => 'pro', 'credit_multiplier' => 1.5, 'input_per_mtok' => 2.50, 'output_per_mtok' => 15.00, 'auto' => false, 'enabled' => true, 'capabilities' => ['supports_tools' => true, 'write_guard' => 'api'], 'verified_at' => null],
        ['label' => 'Gemini 3 Flash', 'provider' => 'gemini', 'model' => 'gemini-3-flash', 'min_plan' => 'free', 'credit_multiplier' => 1.0, 'input_per_mtok' => 0.50, 'output_per_mtok' => 3.00, 'auto' => false, 'enabled' => true, 'capabilities' => ['supports_tools' => false, 'write_guard' => 'prompt'], 'verified_at' => null],
        ['label' => 'Gemini 3.1 Pro', 'provider' => 'gemini', 'model' => 'gemini-3.1-pro', 'min_plan' => 'pro', 'credit_multiplier' => 1.5, 'input_per_mtok' => 2.00, 'output_per_mtok' => 12.00, 'auto' => false, 'enabled' => true, 'capabilities' => ['supports_tools' => false, 'write_guard' => 'prompt'], 'verified_at' => null],

        // Retired: no longer offered, kept only so the sysadmin spend widget can
        // price ai_credit_transactions rows that still name these models.
        ['label' => 'Sonnet 4.6', 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'min_plan' => 'free', 'credit_multiplier' => 1.0, 'input_per_mtok' => 3.00, 'output_per_mtok' => 15.00, 'auto' => false, 'enabled' => false, 'capabilities' => null, 'verified_at' => null],
        ['label' => 'Opus 4.7', 'provider' => 'anthropic', 'model' => 'claude-opus-4-7', 'min_plan' => 'pro', 'credit_multiplier' => 3.0, 'input_per_mtok' => 5.00, 'output_per_mtok' => 25.00, 'auto' => false, 'enabled' => false, 'capabilities' => null, 'verified_at' => null],
    ],

];
