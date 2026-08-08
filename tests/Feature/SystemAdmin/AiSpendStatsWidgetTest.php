<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\SystemAdmin\Filament\Widgets\AiSpendStatsWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

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

it('uses a half-open range so the previous-month boundary is not double-counted', function (): void {
    $monthStart = now()->startOfMonth();

    AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Chat,
        'credits_charged' => 7,
        'created_at' => $monthStart->copy()->subMicrosecond(),
    ]);

    AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Chat,
        'credits_charged' => 3,
        'created_at' => $monthStart,
    ]);

    $component = livewire(AiSpendStatsWidget::class)->assertOk();
    $stats = invade($component->instance())->getStats();

    expect($stats[0]->getValue())->toBe(number_format(3))
        ->and($stats[1]->getDescription())->toContain(number_format(7));
});

it('reports monthly ai cost in dollars from token usage', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-06-15 12:00:00', new DateTimeZone('UTC')));
    config()->set('chat.model_costs', ['claude-sonnet-4-6' => ['input_per_mtok' => 3.00, 'output_per_mtok' => 15.00]]);

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
    config()->set('chat.model_costs', ['claude-sonnet-4-6' => ['input_per_mtok' => 3.00, 'output_per_mtok' => 15.00]]);

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
