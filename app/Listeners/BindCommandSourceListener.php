<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\CreationSource;
use App\Support\CurrentSource;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Container\Attributes\Scoped;
use Symfony\Component\Console\Input\InputInterface;

#[Scoped]
final class BindCommandSourceListener
{
    private ?InputInterface $boundBy = null;

    public function handle(CommandStarting|CommandFinished $event): void
    {
        if ($event instanceof CommandStarting) {
            $this->bind($event->input);

            return;
        }

        if ($event->input === $this->boundBy) {
            CurrentSource::forget();

            $this->boundBy = null;
        }
    }

    private function bind(InputInterface $input): void
    {
        if ($this->boundBy instanceof InputInterface || CurrentSource::isBound()) {
            return;
        }

        CurrentSource::bind(CreationSource::SYSTEM);

        $this->boundBy = $input;
    }
}
