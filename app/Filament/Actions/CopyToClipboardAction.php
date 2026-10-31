<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Illuminate\Support\Js;

final class CopyToClipboardAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->icon('heroicon-o-clipboard-document');
    }

    public function copies(string|Closure $value, string $notification): static
    {
        return $this->actionJs(fn (Action $action): string => sprintf(
            'navigator.clipboard.writeText(%s).then(() => new FilamentNotification().title(%s).success().send()); close()',
            Js::from($action->evaluate($value)),
            Js::from($notification),
        ));
    }
}
