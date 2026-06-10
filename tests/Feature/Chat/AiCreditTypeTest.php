<?php

declare(strict_types=1);

use Relaticle\Chat\Enums\AiCreditType;

it('exposes an adjustment case for sysadmin grants', function (): void {
    expect(AiCreditType::Adjustment->value)->toBe('adjustment');
});

it('keeps the existing transactional cases unchanged', function (): void {
    expect(AiCreditType::Chat->value)->toBe('chat')
        ->and(AiCreditType::Summary->value)->toBe('summary')
        ->and(AiCreditType::Embedding->value)->toBe('embedding');
});
