<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\SystemAdmin\Filament\Widgets\AiSpendStatsWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Tests\Helpers\ChatCatalog;

mutates(AiSpendStatsWidget::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

it('renders the widget', function (): void {
    livewire(AiSpendStatsWidget::class)
        ->assertSuccessful();
});

it('excludes Adjustment transactions from spend totals', function (): void {
    AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Chat,
        'model' => 'claude-sonnet-4-6',
        'credits_charged' => 25,
        'created_at' => now(),
    ]);

    AiCreditTransaction::factory()->adjustment()->create([
        'credits_charged' => 1_000,
        'created_at' => now(),
    ]);

    $component = livewire(AiSpendStatsWidget::class)->assertOk();
    $stats = invade($component->instance())->getStats();

    expect($stats[0]->getValue())->toBe(number_format(25));
});

it('excludes Refund transactions so a failed/refunded job does not inflate spend', function (): void {
    AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Chat,
        'model' => 'claude-sonnet-4-6',
        'credits_charged' => 10,
        'created_at' => now(),
    ]);

    AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Refund,
        'model' => 'system',
        'credits_charged' => 1,
        'created_at' => now(),
    ]);

    $component = livewire(AiSpendStatsWidget::class)->assertOk();
    $stats = invade($component->instance())->getStats();

    expect($stats[0]->getValue())->toBe(number_format(10));
});

it('splits current and previous spend at the period boundary', function (): void {
    AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Chat,
        'credits_charged' => 7,
        'created_at' => now()->subDays(31),
    ]);

    AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Chat,
        'credits_charged' => 3,
        'created_at' => now()->subDay(),
    ]);

    $component = livewire(AiSpendStatsWidget::class)->assertOk();
    $stats = invade($component->instance())->getStats();

    expect($stats[0]->getValue())->toBe(number_format(3))
        ->and($stats[1]->getDescription())->toContain(number_format(7));
});

it('respects the dashboard period filter', function (): void {
    AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Chat,
        'credits_charged' => 5,
        'created_at' => now()->subDays(10),
    ]);

    AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Chat,
        'credits_charged' => 2,
        'created_at' => now()->subDay(),
    ]);

    $component = livewire(AiSpendStatsWidget::class, ['pageFilters' => ['period' => '7']])->assertOk();
    $stats = invade($component->instance())->getStats();

    expect($stats[0]->getValue())->toBe(number_format(2))
        ->and($stats[0]->getDescription())->toBe('Last 7 days');
});

it('reports period ai cost in dollars from token usage', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('UTC')));
    config()->set('chat.models', [ChatCatalog::entry(['model' => 'claude-sonnet-4-6', 'input_per_mtok' => 3.00, 'output_per_mtok' => 15.00])]);
    app()->forgetInstance(ModelRegistry::class);

    AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Chat,
        'model' => 'claude-sonnet-4-6',
        'input_tokens' => 2_000_000,
        'output_tokens' => 1_000_000,
        'credits_charged' => 10,
        'created_at' => now(),
    ]);

    // 2M input x $3 + 1M output x $15 = $21.00
    livewire(AiSpendStatsWidget::class)->assertSee('$21.00');
});

it('surfaces models with no configured rate as unpriced instead of silently treating them as free', function (): void {
    config()->set('chat.models', [ChatCatalog::entry(['model' => 'claude-sonnet-4-6', 'input_per_mtok' => 3.00, 'output_per_mtok' => 15.00])]);
    app()->forgetInstance(ModelRegistry::class);

    AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Chat,
        'model' => 'qwen-2.5',
        'input_tokens' => 1_000_000,
        'output_tokens' => 1_000_000,
        'credits_charged' => 5,
        'created_at' => now(),
    ]);

    livewire(AiSpendStatsWidget::class)
        ->assertSee('$0.00')
        ->assertSee('Unpriced models: qwen-2.5');
});

it('keeps the upper-bound caveat alongside the unpriced list in a mixed month', function (): void {
    config()->set('chat.models', [ChatCatalog::entry(['model' => 'claude-sonnet-4-6', 'input_per_mtok' => 3.00, 'output_per_mtok' => 15.00])]);
    app()->forgetInstance(ModelRegistry::class);

    AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Chat,
        'model' => 'claude-sonnet-4-6',
        'input_tokens' => 2_000_000,
        'output_tokens' => 1_000_000,
        'credits_charged' => 10,
        'created_at' => now(),
    ]);

    AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Chat,
        'model' => 'gpt-5.5',
        'input_tokens' => 1_000_000,
        'output_tokens' => 1_000_000,
        'credits_charged' => 5,
        'created_at' => now(),
    ]);

    livewire(AiSpendStatsWidget::class)
        ->assertSee('$21.00')
        ->assertSee('Upper bound')
        ->assertSee('Unpriced models: gpt-5.5');
});

it('treats a malformed rate entry as unpriced instead of coercing it to zero cost', function (): void {
    config()->set('chat.models', [ChatCatalog::entry(['model' => 'claude-sonnet-4-6', 'input_per_mtok' => 3.00, 'output_per_mtok' => null])]);
    app()->forgetInstance(ModelRegistry::class);

    AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Chat,
        'model' => 'claude-sonnet-4-6',
        'input_tokens' => 2_000_000,
        'output_tokens' => 1_000_000,
        'credits_charged' => 10,
        'created_at' => now(),
    ]);

    livewire(AiSpendStatsWidget::class)
        ->assertSee('$0.00')
        ->assertSee('Unpriced models: claude-sonnet-4-6');
});

it('ignores zero-token settlement rows instead of listing them as unpriced models', function (): void {
    config()->set('chat.models', [ChatCatalog::entry(['model' => 'claude-sonnet-4-6', 'input_per_mtok' => 3.00, 'output_per_mtok' => 15.00])]);
    app()->forgetInstance(ModelRegistry::class);

    // settleReservedMinimum() books cancelled and timed-out turns under this
    // synthetic model with zero tokens; they cost nothing to serve.
    AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Chat,
        'model' => 'incomplete',
        'input_tokens' => 0,
        'output_tokens' => 0,
        'credits_charged' => 1,
        'created_at' => now(),
    ]);

    livewire(AiSpendStatsWidget::class)
        ->assertSee('$0.00')
        ->assertDontSee('Unpriced models');
});

it('has a cost rate on every catalog entry the app can select', function (): void {
    $registry = resolve(ModelRegistry::class);

    $hostedModels = collect(config('chat.models'))
        ->filter(fn (array $entry): bool => ($entry['enabled'] ?? true) === true)
        ->pluck('model')
        ->all();

    expect($hostedModels)->not->toBeEmpty();

    foreach ($hostedModels as $model) {
        expect($registry->ratesFor((string) $model))->not->toBeNull("{$model} has no rate");
    }
});

function actAsAiSpendAdminInZone(string $timezone): void
{
    test()->actingAs(SystemAdministrator::factory()->create(['timezone' => $timezone]), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
}

it('ends the comparison window at the same elapsed point as today, on the administrator calendar', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));
    actAsAiSpendAdminInZone('Asia/Yerevan');

    /**
     * For a 7 day period the comparison window runs from midnight on Aug 14 in
     * Yerevan (2026-08-13 20:00 UTC) to 14:31 on Aug 20 there (2026-08-20 10:31
     * UTC), which is the same elapsed time the current window covers.
     */
    $spend = fn (string $utc, int $credits): AiCreditTransaction => AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Chat,
        'model' => 'claude-sonnet-4-6',
        'credits_charged' => $credits,
        'created_at' => Date::parse($utc, 'UTC'),
    ]);

    $spend('2026-08-13 19:00:00', 7);   // Aug 13 23:00 in Yerevan, before the window opens
    $spend('2026-08-13 21:00:00', 50);  // Aug 14 01:00 in Yerevan, inside
    $spend('2026-08-20 11:00:00', 9);   // Aug 20 15:00 in Yerevan, past the elapsed point

    $component = livewire(AiSpendStatsWidget::class, ['pageFilters' => ['period' => '7']])->assertOk();
    $stats = invade($component->instance())->getStats();

    expect($stats[1]->getDescription())->toBe('Previous period: 50');
});
