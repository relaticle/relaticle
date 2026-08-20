<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Forms\Components\Select;

it('applies a 250ms search debounce inside the app panel', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));

    expect(Select::make('example')->getSearchDebounce())->toBe(250);
});

it('leaves the framework default debounce outside the app panel', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    expect(Select::make('example')->getSearchDebounce())->toBe(1000);
});
