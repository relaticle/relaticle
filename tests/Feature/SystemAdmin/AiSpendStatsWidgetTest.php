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
