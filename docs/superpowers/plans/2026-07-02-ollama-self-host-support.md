# Ollama Self-Host Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let self-hosters run the chat assistant against their own Ollama server by setting `OLLAMA_MODEL` in `.env`, with the model appearing in the chat picker only when configured, while keeping cloud behavior byte-identical.

**Architecture:** Extend the closed `AiModel` enum with a config-backed `Ollama` case plus an `available()` gate; generalize `AiModelResolver`'s hardcoded fallbacks into an availability-aware Auto chain (Sonnet → GPT-5.5 → Ollama); make the duplicated model-picker JS arrays backend-driven from `AiModel::pickerOptions()`. `laravel/ai`'s Ollama driver (text, streaming, tools) is used as-is — zero package changes.

**Tech Stack:** Laravel 13, PHP 8.4 enums, laravel/ai v0 (Ollama gateway), Livewire 4 + Alpine (Blade-embedded picker), Pest 4.

**Spec:** `docs/superpowers/specs/2026-07-02-ollama-self-host-support-design.md`

## Global Constraints

- PostgreSQL only; no new migrations needed for this feature.
- Never use `env()` outside config files — read via `config('ai.providers...')`.
- All parameters and return types explicitly typed (100% type coverage gate).
- No new PHPStan ignores.
- No comments in tests; test names carry the meaning.
- `phpunit.xml` and `phpunit.ci.xml` must stay in sync (`TestSuiteIntegrityTest` enforces).
- Before each commit: `vendor/bin/pint --dirty --format agent`, then `vendor/bin/phpstan analyse`, then the task's targeted tests. Run `vendor/bin/rector --dry-run` and `composer test:type-coverage` in the final verification task (they cover the whole diff at once).
- Conventional commit messages, no AI attribution of any kind.
- `docs/` is gitignored via `/docs/*` but plan/spec files are conventionally force-added (`git add -f`).
- Blade/UI changes must be verified with agent-browser screenshots (light + dark) before the task is reported done.

---

### Task 1: Backend model registry — config, `AiModel::Ollama`, availability, resolver chain

**Files:**
- Modify: `config/ai.php` (ollama provider entry, ~line 104)
- Modify: `packages/Chat/src/Enums/AiModel.php` (full rewrite shown below)
- Modify: `packages/Chat/src/Services/AiModelResolver.php` (full rewrite shown below)
- Modify: `app/Enums/Plan.php:48-54` (`allowedModels()`)
- Modify: `phpunit.xml` + `phpunit.ci.xml` (add fake cloud keys so availability checks are deterministic in tests)
- Test: `tests/Feature/Chat/AiModelResolverTest.php` (append)

**Interfaces:**
- Consumes: `laravel/ai` reads `config('ai.providers.ollama.models.text.default')` natively (`OllamaProvider::defaultTextModel()`); `Plan::allowsModel(AiModel $model): bool` exists.
- Produces (later tasks rely on these exact signatures):
  - `AiModel::Ollama` case with value `'ollama'`
  - `AiModel->available(): bool`
  - `AiModel::pickerOptions(): array` returning `list<array{value: string, label: string, provider: string|null}>`
  - `AiModelResolver->resolve(User $user, ?string $override = null): array{provider: string|null, model: string|null}` (unchanged signature, new fallback behavior)

- [ ] **Step 1: Make cloud-model availability deterministic in the test environment**

The new `available()` method checks `config('ai.providers.anthropic.key')` / `openai.key`. CI has no keys, so without fake ones the existing test "honors the users preference when their plan allows it" (expects Opus) would break. Add fake keys to BOTH phpunit files, in the `<php>` section alongside the existing `<env>` entries:

In `phpunit.xml` and `phpunit.ci.xml` (identical lines in both — `TestSuiteIntegrityTest` fails on drift):

```xml
<env name="ANTHROPIC_API_KEY" value="fake-anthropic-key"/>
<env name="OPENAI_API_KEY" value="fake-openai-key"/>
```

Place them next to the other `<env name=...>` entries. No real requests are made — chat tests use `Queue::fake()` / `CrmAssistant::fake()`.

- [ ] **Step 2: Write the failing resolver tests**

Append to `tests/Feature/Chat/AiModelResolverTest.php` (file already has `mutates(AiModelResolver::class)` and imports `Plan`, `User`, `AiModel`, `AiModelResolver`):

```php
it('resolves an explicit Ollama request when Ollama is configured', function (): void {
    config()->set('ai.providers.ollama.models.text.default', 'qwen3:14b');

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'ollama');

    expect($resolved['provider'])->toBe('ollama');
    expect($resolved['model'])->toBe('qwen3:14b');
});

it('falls back to Sonnet when Ollama is requested but not configured', function (): void {
    config()->set('ai.providers.ollama.models.text.default', null);

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'ollama');

    expect($resolved['provider'])->toBe('anthropic');
    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('resolves Auto to Ollama when no cloud provider is configured', function (): void {
    config()->set('ai.providers.anthropic.key', null);
    config()->set('ai.providers.openai.key', null);
    config()->set('ai.providers.ollama.models.text.default', 'qwen3:14b');

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'auto');

    expect($resolved['provider'])->toBe('ollama');
    expect($resolved['model'])->toBe('qwen3:14b');
});

it('resolves Auto to Sonnet when Anthropic is configured alongside Ollama', function (): void {
    config()->set('ai.providers.ollama.models.text.default', 'qwen3:14b');

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'auto');

    expect($resolved['provider'])->toBe('anthropic');
    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('skips plan-disallowed models in the Auto chain and falls back to Sonnet', function (): void {
    config()->set('ai.providers.anthropic.key', null);
    config()->set('ai.providers.ollama.models.text.default', null);

    $user = User::factory()->withPersonalTeam()->create();

    $resolved = resolve(AiModelResolver::class)->resolve($user, 'auto');

    expect($resolved['model'])->toBe('claude-sonnet-4-6');
});

it('honors an Ollama default-model preference when configured', function (): void {
    config()->set('ai.providers.ollama.models.text.default', 'llama3.1:70b');

    $user = User::factory()->withPersonalTeam()->create();
    $user->ai_preferences = ['default_model' => 'ollama'];
    $user->save();
    $user->refresh();

    $resolved = resolve(AiModelResolver::class)->resolve($user, null);

    expect($resolved['provider'])->toBe('ollama');
    expect($resolved['model'])->toBe('llama3.1:70b');
});
```

Note on the fifth test: openai key IS set (fake, from phpunit.xml) but GPT-5.5 is not Free-plan-allowed, and anthropic/ollama are unavailable — the chain must respect the plan and land on the final Sonnet fallback.

- [ ] **Step 3: Run the new tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Chat/AiModelResolverTest.php`

Expected: the 6 new tests FAIL (`'ollama'` is not a valid `AiModel` value yet, so requests resolve to Sonnet; Auto never resolves to ollama). The 5 pre-existing tests must PASS.

- [ ] **Step 4: Add the Ollama models block to `config/ai.php`**

Replace the existing ollama entry (lines 104-108):

```php
'ollama' => [
    'driver' => 'ollama',
    'key' => env('OLLAMA_API_KEY', ''),
    'url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
],
```

with:

```php
'ollama' => [
    'driver' => 'ollama',
    'key' => env('OLLAMA_API_KEY', ''),
    'url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
    'models' => [
        'text' => [
            'default' => env('OLLAMA_MODEL'),
        ],
    ],
],
```

- [ ] **Step 5: Rewrite `packages/Chat/src/Enums/AiModel.php`**

Full new file content:

```php
<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

enum AiModel: string
{
    case Auto = 'auto';
    case ClaudeSonnet = 'claude-sonnet';
    case ClaudeOpus = 'claude-opus';
    case Gpt5_5 = 'gpt-5-5';
    case Gpt5_4 = 'gpt-5-4';
    case Gemini3Flash = 'gemini-3-flash';
    case Gemini31Pro = 'gemini-3-1-pro';
    case Ollama = 'ollama';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Auto',
            self::ClaudeSonnet => 'Sonnet 4.6',
            self::ClaudeOpus => 'Opus 4.7',
            self::Gpt5_5 => 'GPT 5.5',
            self::Gpt5_4 => 'GPT 5.4',
            self::Gemini3Flash => 'Gemini 3 Flash',
            self::Gemini31Pro => 'Gemini 3.1 Pro',
            self::Ollama => self::ollamaModelTag() ?? 'Ollama',
        };
    }

    public function provider(): ?string
    {
        return match ($this) {
            self::Auto => null,
            self::ClaudeSonnet, self::ClaudeOpus => 'anthropic',
            self::Gpt5_5, self::Gpt5_4 => 'openai',
            self::Gemini3Flash, self::Gemini31Pro => 'gemini',
            self::Ollama => 'ollama',
        };
    }

    public function modelId(): ?string
    {
        return match ($this) {
            self::Auto => null,
            self::ClaudeSonnet => 'claude-sonnet-4-6',
            self::ClaudeOpus => 'claude-opus-4-7',
            self::Gpt5_5 => 'gpt-5.5',
            self::Gpt5_4 => 'gpt-5.4',
            self::Gemini3Flash => 'gemini-3-flash',
            self::Gemini31Pro => 'gemini-3.1-pro',
            self::Ollama => self::ollamaModelTag(),
        };
    }

    /**
     * Whether this model can serve requests on this installation. Cloud
     * models need their provider's API key; Ollama needs an explicitly
     * configured model tag. Gemini stays excluded until laravel/ai's Gemini
     * driver supports tool_config (see the note on CrmAssistant).
     */
    public function available(): bool
    {
        return match ($this) {
            self::Auto => true,
            self::ClaudeSonnet, self::ClaudeOpus => filled(config('ai.providers.anthropic.key')),
            self::Gpt5_5, self::Gpt5_4 => filled(config('ai.providers.openai.key')),
            self::Gemini3Flash, self::Gemini31Pro => false,
            self::Ollama => self::ollamaModelTag() !== null,
        };
    }

    public function creditMultiplier(): float
    {
        return match ($this) {
            self::Auto, self::ClaudeSonnet, self::Gemini3Flash, self::Ollama => 1.0,
            self::ClaudeOpus => 3.0,
            self::Gpt5_5, self::Gpt5_4 => 1.5,
            self::Gemini31Pro => 1.5,
        };
    }

    public static function multiplierForModelId(string $modelId): float
    {
        foreach (self::cases() as $case) {
            if ($case->modelId() === $modelId) {
                return $case->creditMultiplier();
            }
        }

        return 1.0;
    }

    /**
     * The options rendered by the chat model pickers.
     *
     * @return list<array{value: string, label: string, provider: string|null}>
     */
    public static function pickerOptions(): array
    {
        return collect(self::cases())
            ->filter(fn (self $model): bool => $model->available())
            ->map(fn (self $model): array => [
                'value' => $model->value,
                'label' => $model->label(),
                'provider' => $model->provider(),
            ])
            ->values()
            ->all();
    }

    private static function ollamaModelTag(): ?string
    {
        $tag = config('ai.providers.ollama.models.text.default');

        return is_string($tag) && $tag !== '' ? $tag : null;
    }
}
```

- [ ] **Step 6: Add Ollama to the Free plan's allowed models**

In `app/Enums/Plan.php`, replace:

```php
    /** @return list<AiModel> */
    public function allowedModels(): array
    {
        return match ($this) {
            self::Free => [AiModel::Auto, AiModel::ClaudeSonnet, AiModel::Gemini3Flash],
            self::Pro, self::Enterprise => AiModel::cases(),
        };
    }
```

with:

```php
    /** @return list<AiModel> */
    public function allowedModels(): array
    {
        return match ($this) {
            self::Free => [AiModel::Auto, AiModel::ClaudeSonnet, AiModel::Gemini3Flash, AiModel::Ollama],
            self::Pro, self::Enterprise => AiModel::cases(),
        };
    }
```

(Ollama is self-hosted infrastructure — not plan-gated. Pro/Enterprise pick it up via `cases()`.)

- [ ] **Step 7: Rewrite `packages/Chat/src/Services/AiModelResolver.php`**

Full new file content:

```php
<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Enums\Plan;
use App\Models\User;
use Relaticle\Chat\Enums\AiModel;

final readonly class AiModelResolver
{
    /**
     * Resolve the provider and model for a chat request.
     *
     * `Auto` (and any unavailable or plan-disallowed request) resolves to the
     * first available, plan-allowed model in the priority chain: Claude
     * Sonnet, then GPT-5.5, then Ollama. Smaller models like Haiku cannot be
     * trusted to call CRM write tools reliably -- they tend to hallucinate
     * "task created" without invoking the tool.
     *
     * @return array{provider: string|null, model: string|null}
     */
    public function resolve(User $user, ?string $override = null): array
    {
        $aiModel = $this->resolveModel($user, $override);

        $team = $user->currentTeam;
        $plan = $team !== null ? $team->plan : Plan::default();

        if (! $plan->allowsModel($aiModel) || ! $aiModel->available()) {
            $aiModel = AiModel::Auto;
        }

        if ($aiModel === AiModel::Auto) {
            $aiModel = $this->defaultFor($plan);
        }

        return [
            'provider' => $aiModel->provider(),
            'model' => $aiModel->modelId(),
        ];
    }

    private function resolveModel(User $user, ?string $override): AiModel
    {
        if ($override !== null) {
            $model = AiModel::tryFrom($override);

            if ($model !== null && $model !== AiModel::Auto) {
                return $model;
            }
        }

        $preference = $user->ai_preferences['default_model'] ?? 'auto';
        $model = AiModel::tryFrom($preference);

        if ($model !== null && $model !== AiModel::Auto) {
            return $model;
        }

        return AiModel::Auto;
    }

    private function defaultFor(Plan $plan): AiModel
    {
        foreach ([AiModel::ClaudeSonnet, AiModel::Gpt5_5, AiModel::Ollama] as $candidate) {
            if ($candidate->available() && $plan->allowsModel($candidate)) {
                return $candidate;
            }
        }

        return AiModel::ClaudeSonnet;
    }
}
```

Note: the explicit `provider() === 'gemini'` branch from the old code is gone — gemini's `available()` is `false`, so the availability check now covers it. The existing test "falls back to ClaudeSonnet when a Gemini model is requested" proves this.

- [ ] **Step 8: Run the full resolver test file to verify everything passes**

Run: `php artisan test --compact tests/Feature/Chat/AiModelResolverTest.php`
Expected: ALL tests pass (5 pre-existing + 6 new).

- [ ] **Step 9: Run the neighboring gate tests to catch regressions**

Run: `php artisan test --compact tests/Feature/Chat/ModelGatePlanTest.php tests/Arch/TestSuiteIntegrityTest.php`
Expected: PASS (proves the phpunit.xml/phpunit.ci.xml edit stayed in sync and controller gating is intact).

- [ ] **Step 10: Style + static analysis**

Run: `vendor/bin/pint --dirty --format agent` then `vendor/bin/phpstan analyse`
Expected: pint clean, phpstan no NEW errors. If phpstan flags a missing match arm anywhere over `AiModel` (the enum gained a case), fix that match — do not add ignores.

- [ ] **Step 11: Commit**

```bash
git add config/ai.php packages/Chat/src/Enums/AiModel.php packages/Chat/src/Services/AiModelResolver.php app/Enums/Plan.php phpunit.xml phpunit.ci.xml tests/Feature/Chat/AiModelResolverTest.php
git commit -m "feat(chat): add config-gated ollama model with availability-aware resolution"
```

---

### Task 2: HTTP-level gate coverage for Ollama

**Files:**
- Test: `tests/Feature/Chat/ModelGatePlanTest.php` (append)

**Interfaces:**
- Consumes: `AiModel::Ollama` (`'ollama'` value) from Task 1; existing `ChatDocument::fromText()` helper and the `POST /chat/{conversationId}` route.
- Produces: nothing new — regression coverage that `'ollama'` passes `Rule::enum` validation and the Free-plan gate end to end.

- [ ] **Step 1: Write the test**

Append to `tests/Feature/Chat/ModelGatePlanTest.php` (imports for `Queue`, `DB`, `Str`, `AiCreditBalance`, `ChatDocument` already exist in the file):

```php
it('allows a Free user to pick Ollama when it is configured', function (): void {
    Queue::fake();
    config()->set('ai.providers.ollama.models.text.default', 'qwen3:14b');

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)->postJson("/chat/{$conversationId}", [
        'document' => ChatDocument::fromText('hi'),
        'model' => 'ollama',
    ]);

    $response->assertStatus(200);
});
```

- [ ] **Step 2: Run it**

Run: `php artisan test --compact tests/Feature/Chat/ModelGatePlanTest.php`
Expected: ALL PASS immediately (the production change already landed in Task 1 — this is entry-point coverage, not TDD of new behavior).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Chat/ModelGatePlanTest.php
git commit -m "test(chat): cover free-plan ollama requests through the send endpoint"
```

---

### Task 3: Backend-driven model picker in both Blade surfaces

**Files:**
- Modify: `packages/Chat/resources/views/livewire/chat/chat-interface.blade.php:654-677`
- Modify: `packages/Chat/resources/views/filament/pages/dashboard.blade.php:98-134`
- Test: Create `tests/Feature/Chat/ModelPickerVisibilityTest.php`

**Interfaces:**
- Consumes: `AiModel::pickerOptions(): list<array{value: string, label: string, provider: string|null}>` from Task 1; Livewire component `Relaticle\Chat\Livewire\Chat\ChatInterface`; Filament page `App\Filament\Pages\Dashboard`.
- Produces: picker option lists rendered from the backend; `ri-server-line` icon + neutral gray color for the `ollama` provider key.

- [ ] **Step 1: Write the failing visibility tests**

Create `tests/Feature/Chat/ModelPickerVisibilityTest.php` (new file is justified: config-conditional picker visibility is a new feature with no existing coverage; `tests/Pest.php` already binds `TestCase` + `LazilyRefreshDatabase` for the Feature suite):

```php
<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Relaticle\Chat\Enums\AiModel;
use Relaticle\Chat\Livewire\Chat\ChatInterface;

use function Pest\Livewire\livewire;

mutates(AiModel::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

it('shows the Ollama model in the chat picker when configured', function (): void {
    config()->set('ai.providers.ollama.models.text.default', 'qwen3:14b');

    Livewire::test(ChatInterface::class)
        ->assertSee('qwen3:14b');
});

it('hides the Ollama model from the chat picker when not configured', function (): void {
    config()->set('ai.providers.ollama.models.text.default', null);

    Livewire::test(ChatInterface::class)
        ->assertDontSee('qwen3:14b');
});

it('hides cloud models whose provider key is not configured', function (): void {
    config()->set('ai.providers.openai.key', null);

    Livewire::test(ChatInterface::class)
        ->assertSee('Sonnet 4.6')
        ->assertDontSee('GPT 5.5');
});

it('shows the Ollama model on the dashboard picker when configured', function (): void {
    config()->set('ai.providers.ollama.models.text.default', 'qwen3:14b');

    livewire(Dashboard::class)
        ->assertSee('qwen3:14b');
});

it('hides the Ollama model from the dashboard picker when not configured', function (): void {
    config()->set('ai.providers.ollama.models.text.default', null);

    livewire(Dashboard::class)
        ->assertDontSee('qwen3:14b');
});
```

(Anthropic/OpenAI keys are fake-set in phpunit.xml from Task 1, so 'Sonnet 4.6' renders by default. Assert on model labels, not the string `ollama` — the provider-icon map key `ollama` will exist in the HTML unconditionally.)

- [ ] **Step 2: Run to verify the "shows" tests fail**

Run: `php artisan test --compact tests/Feature/Chat/ModelPickerVisibilityTest.php`
Expected: the two "shows the Ollama model" tests FAIL (options are still hardcoded JS arrays without ollama). The "hides" tests pass trivially. If `Dashboard` needs feature-flag setup to render (see `DashboardMyTasksRenderTest.php` which defines `Feature::define(OnboardSeed::class, false)`), copy that `beforeEach` line into this file.

- [ ] **Step 3: Replace the hardcoded options in `chat-interface.blade.php`**

Around line 654, replace:

```js
    modelOptions: [
        { value: 'auto', label: 'Auto', provider: null },
        { value: 'claude-sonnet', label: 'Sonnet 4.6', provider: 'anthropic' },
        { value: 'claude-opus', label: 'Opus 4.7', provider: 'anthropic' },
        { value: 'gpt-5-5', label: 'GPT 5.5', provider: 'openai' },
        { value: 'gpt-5-4', label: 'GPT 5.4', provider: 'openai' },
    ],

    providerIcons: @js([
        'anthropic' => svg('ri-claude-fill')->toHtml(),
        'openai' => svg('ri-openai-fill')->toHtml(),
    ]),
```

with:

```js
    modelOptions: @js(\Relaticle\Chat\Enums\AiModel::pickerOptions()),

    providerIcons: @js([
        'anthropic' => svg('ri-claude-fill')->toHtml(),
        'openai' => svg('ri-openai-fill')->toHtml(),
        'ollama' => svg('ri-server-line')->toHtml(),
    ]),
```

And in the same file (~line 672), replace:

```js
    providerIconColor(provider) {
        return ({
            anthropic: 'text-[#D4763C]',
            openai: 'text-gray-900 dark:text-gray-200',
        })[provider] || '';
    },
```

with:

```js
    providerIconColor(provider) {
        return ({
            anthropic: 'text-[#D4763C]',
            openai: 'text-gray-900 dark:text-gray-200',
            ollama: 'text-gray-500 dark:text-gray-400',
        })[provider] || '';
    },
```

(`ri-server-line`: Remix v3.9 ships no Ollama brand icon; line variant per the UI-icon rules. The localStorage restore paths in this file already filter against `modelOptions`, so a stale saved value for a now-hidden model safely falls back — no changes needed there.)

- [ ] **Step 4: Replace the hardcoded options in `dashboard.blade.php`**

Around line 98, replace:

```js
            modelOptions: [
                { value: 'auto', label: 'Auto', provider: null },
                { value: 'claude-sonnet', label: 'Sonnet 4.6', provider: 'anthropic' },
                { value: 'claude-opus', label: 'Opus 4.7', provider: 'anthropic' },
                { value: 'gpt-5-5', label: 'GPT 5.5', provider: 'openai' },
                { value: 'gpt-5-4', label: 'GPT 5.4', provider: 'openai' },
            ],
            providerIcons: @js([
                'anthropic' => svg('ri-claude-fill')->toHtml(),
                'openai' => svg('ri-openai-fill')->toHtml(),
            ]),
```

with:

```js
            modelOptions: @js(\Relaticle\Chat\Enums\AiModel::pickerOptions()),
            providerIcons: @js([
                'anthropic' => svg('ri-claude-fill')->toHtml(),
                'openai' => svg('ri-openai-fill')->toHtml(),
                'ollama' => svg('ri-server-line')->toHtml(),
            ]),
```

In the same file (~line 114), replace:

```js
            providerIconColor(provider) {
                return ({
                    anthropic: 'text-[#D4763C]',
                    openai: 'text-gray-900 dark:text-gray-200',
                })[provider] || '';
            },
```

with:

```js
            providerIconColor(provider) {
                return ({
                    anthropic: 'text-[#D4763C]',
                    openai: 'text-gray-900 dark:text-gray-200',
                    ollama: 'text-gray-500 dark:text-gray-400',
                })[provider] || '';
            },
```

And harden the dashboard's `init()` (~line 131) — unlike chat-interface, it doesn't check `modelOptions`, so a saved preference for a now-hidden model would stick. Replace:

```js
            init() {
                const candidate = defaultModel || 'auto';
                this.selectedModel = this.allowedModels.includes(candidate) ? candidate : 'auto';
            },
```

with:

```js
            init() {
                const candidate = defaultModel || 'auto';
                this.selectedModel = this.allowedModels.includes(candidate)
                    && this.modelOptions.some((o) => o.value === candidate)
                    ? candidate
                    : 'auto';
            },
```

- [ ] **Step 5: Run the visibility tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Chat/ModelPickerVisibilityTest.php`
Expected: ALL 5 PASS.

- [ ] **Step 6: Run the neighboring interface tests to catch regressions**

Run: `php artisan test --compact tests/Feature/Chat/ChatInterfacePromptForwardingTest.php tests/Feature/Chat/DashboardMyTasksRenderTest.php tests/Arch/TestSuiteIntegrityTest.php`
Expected: PASS.

- [ ] **Step 7: Browser-verify the picker (required for any Blade change)**

Using the `agent-browser-relaticle` skill conventions (derive the real URL from the running app — do not assume):

1. With `OLLAMA_MODEL` unset in `.env`: open the app dashboard, open the model picker, screenshot light + dark. Expected: today's options, no Ollama entry.
2. Set `OLLAMA_MODEL=qwen3:14b` in `.env`, run `php artisan config:clear`, reload, open the picker, screenshot light + dark. Expected: a `qwen3:14b` entry with the server icon; selecting it works.
3. Remove `OLLAMA_MODEL` from `.env` and `php artisan config:clear` again (leave the env clean).

If the workspace has no runnable app environment, report this step as blocked rather than skipping it silently.

- [ ] **Step 8: Style + static analysis**

Run: `vendor/bin/pint --dirty --format agent` then `vendor/bin/phpstan analyse`
Expected: clean / no new errors.

- [ ] **Step 9: Commit**

```bash
git add packages/Chat/resources/views/livewire/chat/chat-interface.blade.php packages/Chat/resources/views/filament/pages/dashboard.blade.php tests/Feature/Chat/ModelPickerVisibilityTest.php
git commit -m "feat(chat): drive model picker options from the backend registry"
```

---

### Task 4: Documentation — `.env.example` and self-hosting guide

**Files:**
- Modify: `.env.example:110-113` (AI block)
- Modify: `packages/Documentation/resources/markdown/self-hosting-guide.md` (new "AI Assistant" subsection between "Optional" and "Feature Flags", ~line 118)

**Interfaces:**
- Consumes: env var names fixed in Task 1 (`OLLAMA_BASE_URL`, `OLLAMA_MODEL`).
- Produces: user-facing docs only; no code.

- [ ] **Step 1: Update `.env.example`**

Replace:

```
# AI chat (laravel/ai). OpenAI is the default provider (config/ai.php);
# Anthropic powers the Claude models in the picker and the summary model.
OPENAI_API_KEY=
ANTHROPIC_API_KEY=
```

with:

```
# AI chat (laravel/ai). OpenAI is the default provider (config/ai.php);
# Anthropic powers the Claude models in the picker and the summary model.
# Models only appear in the chat picker when their provider is configured.
OPENAI_API_KEY=
ANTHROPIC_API_KEY=

# Self-hosted AI via Ollama. Set OLLAMA_MODEL to a tool-calling-capable model
# tag (e.g. qwen3:14b) to add it to the chat model picker.
# OLLAMA_BASE_URL=http://localhost:11434
# OLLAMA_MODEL=
```

- [ ] **Step 2: Add the "AI Assistant" section to the self-hosting guide**

In `packages/Documentation/resources/markdown/self-hosting-guide.md`, insert between the "### Optional" table and "### Feature Flags":

```markdown
### AI Assistant

The AI assistant works with cloud providers, a self-hosted [Ollama](https://ollama.com) server, or both. Models appear in the chat model picker only when their provider is configured — with none configured, the assistant cannot answer.

| Variable | Default | Description |
|----------|---------|-------------|
| `ANTHROPIC_API_KEY` | (empty) | Enables the Claude models in the chat picker. |
| `OPENAI_API_KEY` | (empty) | Enables the GPT models in the chat picker. |
| `OLLAMA_BASE_URL` | `http://localhost:11434` | URL of your Ollama server. From inside a Docker container, `localhost` is the container itself — use `http://host.docker.internal:11434` (or your host's LAN IP) to reach Ollama running on the host. |
| `OLLAMA_MODEL` | (empty) | Ollama model tag (e.g. `qwen3:14b`). Setting this adds the model to the chat picker. |

**Choosing an Ollama model.** The assistant calls over 30 CRM tools (search, create, update, delete). Only models with strong tool-calling support work reliably — `qwen3` and `llama3.1:70b`-class models are good starting points. Small models tend to claim an action succeeded without actually invoking the tool.

**Known limitations with Ollama:**

- Cloud providers enforce one-write-proposal-at-a-time at the API level; Ollama has no equivalent switch, so this is enforced by prompt instructions only. Every write still requires your explicit approval before anything is saved.
- Responses must complete within 120 seconds. On slow hardware a large model may hit this timeout — use a smaller model or a GPU.
```

- [ ] **Step 3: Verify the docs page renders**

Run: `php artisan test --compact tests/ --filter=Documentation`
Expected: PASS (existing documentation smoke tests still render the guide). If no Documentation-filtered tests exist, open `/docs/self-hosting-guide` in the browser session from Task 3 and confirm the new section renders with its table.

- [ ] **Step 4: Commit**

```bash
git add .env.example packages/Documentation/resources/markdown/self-hosting-guide.md
git commit -m "docs: document ollama and cloud AI provider setup for self-hosting"
```

---

### Task 5: Full verification sweep

**Files:**
- No new changes expected — fixes only if a check fails.

**Interfaces:**
- Consumes: the whole diff from Tasks 1-4.
- Produces: green quality gates; evidence for the final report.

- [ ] **Step 1: Sweep for match expressions over the grown enum**

`packages/SystemAdmin` is excluded from PHPStan and has already caused a production `UnhandledMatchError` after an enum grew. Run:

```bash
grep -rn "AiModel" packages/SystemAdmin/ --include="*.php" --include="*.blade.php"
```

Expected: no output (verified during design; re-verify now). If any hit is a `match` over `AiModel`, add an `AiModel::Ollama` arm there.

- [ ] **Step 2: Full pre-commit gate battery**

Run in order, all from the repo root:

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
```

Expected: pint clean; rector proposes nothing (if it does, apply with `vendor/bin/rector`, re-run pint, and amend the relevant commit); phpstan no new errors; type coverage 100%.

- [ ] **Step 3: Run the affected test surface**

```bash
php artisan test --compact tests/Feature/Chat/AiModelResolverTest.php tests/Feature/Chat/ModelGatePlanTest.php tests/Feature/Chat/ModelPickerVisibilityTest.php tests/Feature/Chat/ChatControllerSendTest.php tests/Feature/Chat/ChatInterfacePromptForwardingTest.php tests/Feature/Chat/DashboardMyTasksRenderTest.php tests/Feature/Chat/ChatSidePanelCreditDisplayTest.php
php -d memory_limit=2G vendor/bin/pest tests/Arch --compact
```

Expected: ALL PASS. (The Arch suite needs the raised memory limit locally — known pre-existing OOM at 128M.)

- [ ] **Step 4: Commit any fixes from the sweep**

Only if Steps 1-3 required changes:

```bash
git add -A -- ':!docs'
git commit -m "chore: apply rector/pint fixes from verification sweep"
```

- [ ] **Step 5: Report**

Summarize: commits on the branch, test evidence (counts), browser screenshots from Task 3, and the one accepted behavior note — a request for an unavailable model silently resolves to the Auto chain (matches today's gemini handling).
