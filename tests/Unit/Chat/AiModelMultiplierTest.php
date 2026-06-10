<?php

declare(strict_types=1);

use Relaticle\Chat\Services\CreditService;
use Tests\TestCase;

uses(TestCase::class);

it('charges 3 credits for claude-opus-4-7 with no tool calls', function (): void {
    expect(app(CreditService::class)->calculateCredits('claude-opus-4-7', 0))->toBe(3);
});

it('charges 1 credit for claude-sonnet-4-6 with no tool calls', function (): void {
    expect(app(CreditService::class)->calculateCredits('claude-sonnet-4-6', 0))->toBe(1);
});

it('defaults to 1 credit for unknown model', function (): void {
    expect(app(CreditService::class)->calculateCredits('unknown-xyz', 0))->toBe(1);
});
