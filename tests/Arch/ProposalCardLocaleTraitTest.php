<?php

declare(strict_types=1);

use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Livewire\Concerns\RendersInChatLocale;

it('scopes ProposalCard to the chat locale', function (): void {
    expect(class_uses_recursive(ProposalCard::class))->toHaveKey(RendersInChatLocale::class);
});
