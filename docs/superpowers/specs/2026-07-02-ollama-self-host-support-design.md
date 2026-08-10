# Ollama Support for Self-Hosted Instances — Design

**Date:** 2026-07-02
**Status:** Approved (pending spec review)

## Problem

Self-hosters want to run the Relaticle AI assistant against their own Ollama
server instead of paying for Anthropic/OpenAI. `config/ai.php` already lists an
`ollama` provider (stock `laravel/ai` scaffold), but nothing in Relaticle can
reach it: the model registry (`AiModel` enum), the resolver, the plan gating,
and the UI picker are all closed, hardcoded lists of cloud models. There is
also no documentation for AI setup in the self-hosting guide.

## Goals

1. A self-hoster sets `OLLAMA_MODEL` (and optionally `OLLAMA_BASE_URL`) in
   `.env` and the chat assistant works end to end against their Ollama server.
2. The Ollama option appears in the model picker **only when configured** —
   cloud (relaticle.com) and unconfigured self-hosts see exactly today's UI.
3. Cloud behavior is byte-identical to today when all provider keys are set.
4. The model picker becomes backend-driven (single source of truth), killing
   the duplicated hardcoded JS arrays in two Blade files.

## Non-Goals (deliberate)

- Multiple Ollama models in the picker (single model via env; extend later).
- Disabling the credit/plan system for self-hosted installs (separate feature;
  Ollama turns cost 1 credit like any other model).
- OpenAI-compatible custom endpoints (LM Studio, vLLM). `OPENAI_URL` already
  exists as an undocumented escape hatch.
- Ollama embeddings/images — chat text generation only.

## Feasibility (verified)

`laravel/ai` v0 ships a complete Ollama driver: text generation, streaming,
tool calling, and structured output via `POST api/chat`
(`vendor/laravel/ai/src/Gateway/Ollama/OllamaGateway.php`). `Lab::Ollama =
'ollama'` exists. The `provider:`/`model:` params passed in
`ProcessChatMessage::handle()` override the class-level
`#[Provider(['anthropic', 'openai'])]` attribute on `CrmAssistant`, so the
streaming pipeline, rate gate (string-keyed), retry logic, and credit
settlement are already provider-agnostic. No package changes are needed.

## Design

### 1. Configuration

Use `laravel/ai`'s native config shape — its `OllamaProvider` already reads
`models.text.default` — so no new chat-config keys:

```php
// config/ai.php — extend the existing ollama entry
'ollama' => [
    'driver' => 'ollama',
    'key' => env('OLLAMA_API_KEY', ''),
    'url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
    'models' => [
        'text' => ['default' => env('OLLAMA_MODEL')],
    ],
],
```

`OLLAMA_MODEL=qwen3:14b` set → Ollama exists everywhere in the app.
Unset → invisible everywhere. That is the entire feature switch.

### 2. `AiModel` enum (`packages/Chat/src/Enums/AiModel.php`)

Add `case Ollama = 'ollama'`:

- `provider()` → `'ollama'`
- `modelId()` → the configured tag from
  `config('ai.providers.ollama.models.text.default')`, or `null` when
  unconfigured (consistent with the `?string` return)
- `label()` → the raw configured model tag (e.g. `qwen3:14b`); no label env
- `creditMultiplier()` → `1.0`

New methods:

- `available(): bool` — the "only show when configured" core:
  - `Ollama` → `OLLAMA_MODEL` is filled
  - `ClaudeSonnet`/`ClaudeOpus` → `ANTHROPIC_API_KEY` is filled
  - `Gpt5_5`/`Gpt5_4` → `OPENAI_API_KEY` is filled
  - `Gemini3Flash`/`Gemini31Pro` → `false` (preserves the existing exclusion,
    today done by UI omission — see the `tool_config` comment on
    `CrmAssistant`)
  - `Auto` → `true`
- `pickerOptions(): array` — list of `{value, label, provider}` for available
  cases; the single source the UI consumes.

Rationale for key-presence checks on cloud models: a pure-Ollama self-host
must not show Claude/GPT entries that would 500 on first use. On cloud all
keys are set, so nothing changes.

### 3. `AiModelResolver` (`packages/Chat/src/Services/AiModelResolver.php`)

Generalize the existing gemini-forcing into availability-forcing:

- A requested model that is not `available()` or not plan-allowed falls back
  to Auto resolution (today: hardcoded Sonnet).
- Auto resolves down a priority chain of **available** models:
  `ClaudeSonnet → Gpt5_5 → Ollama`. First available wins.
- If nothing is available, resolution behaves as today with no keys set (the
  turn fails at the provider; already true and out of scope).

Cloud behavior stays byte-identical: Sonnet is available, chain stops there.

### 4. `Plan` (`app/Enums/Plan.php`)

Add `AiModel::Ollama` to `Free`'s `allowedModels()`. Pro/Enterprise already
use `AiModel::cases()`. Self-hosted infrastructure is not plan-gated; credit
accounting is unchanged (1 credit/turn, `multiplierForModelId` falls back to
1.0 for the Ollama tag).

### 5. `ChatController`

No structural change. `Rule::enum(AiModel::class)` accepts `'ollama'`; an
explicit request for an unavailable model passes validation and falls through
the resolver to a safe default — exactly how gemini requests are handled
today.

### 6. UI — backend-driven picker

- Delete both hardcoded `modelOptions` JS arrays
  (`packages/Chat/resources/views/livewire/chat/chat-interface.blade.php:654`,
  `packages/Chat/resources/views/filament/pages/dashboard.blade.php:98`);
  replace with `@js(AiModel::pickerOptions())`.
- Add `ollama` to the `providerIcons` and `providerIconColor` maps. Remix
  v3.9 has no Ollama brand icon, so use `ri-server-line` (line variant per
  UI-icon rules) in neutral gray.
- `allowedModels`, the Pro badge, `selectModel`, and the localStorage restore
  paths already operate generically on `modelOptions` — no changes.

### 7. Documentation

- `packages/Documentation/resources/markdown/self-hosting-guide.md`: new
  "AI Assistant" section — cloud keys (`ANTHROPIC_API_KEY`,
  `OPENAI_API_KEY`), Ollama setup (`OLLAMA_BASE_URL`, `OLLAMA_MODEL`), a
  recommendation to use tool-calling-capable models (qwen3 / llama3.1-70b
  class — the agent exposes ~34 tools), and two documented limitations:
  1. Sequential-write approval enforcement is prompt-only on Ollama (no
     `disable_parallel_tool_use` equivalent in its API).
  2. The 120 s job/agent timeout may be exceeded on slow local hardware.
- `.env.example`: commented `OLLAMA_BASE_URL` / `OLLAMA_MODEL` entries next to
  the existing AI keys.

### 8. Testing

Extend existing files; no new suites, entry-point-level per Testing Trophy:

- `tests/Feature/Chat/AiModelResolverTest.php`: Ollama resolves when
  configured; falls back when not; Auto chain picks Ollama when cloud keys are
  absent (drive via `config()->set(...)`).
- `tests/Feature/Chat/ModelGatePlanTest.php`: Free plan allows Ollama when
  configured.
- Picker rendering: assert the Ollama option appears/disappears with config in
  the existing chat interface test file.
- `CrmAssistant::fake()` for anything streaming — no real Ollama server in CI.

## Risks / Accepted Trade-offs

- **Tool-calling quality on local models** is the self-hoster's
  responsibility; documented, not enforced.
- **Sequential-write guard** is prompt-only on Ollama (accepted; write tools
  are still proposal-gated server-side, so the worst case is multiple pending
  proposals, not unapproved writes).
- **`label()` returns a raw model tag** for Ollama — acceptable, honest, and
  avoids a second config knob.

## Decisions Log

- Q1: Single model via env (`OLLAMA_MODEL`) — not a list, not `/api/tags`.
- Q2: Ollama treated like any model for credits/plans (1 credit/turn, all
  plans).
- Q3: Auto falls back through available providers (Sonnet → GPT-5.5 →
  Ollama).
- Q4: Picker becomes backend-driven from the enum (single source of truth).
- Flag a: `available()` also hides unconfigured cloud models.
- Flag b: Auto priority chain order as above.
- Flag c: Ollama picker label = raw model tag.
- Flag d: `ri-server-line` icon for Ollama.
