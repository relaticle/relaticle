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

it('returns a color and label for every case so Filament badges never hit an unhandled match', function (AiCreditType $type): void {
    expect($type->getColor())->toBeString()->not->toBeEmpty()
        ->and($type->getLabel())->toBeString()->not->toBeEmpty();
})->with(AiCreditType::cases());
