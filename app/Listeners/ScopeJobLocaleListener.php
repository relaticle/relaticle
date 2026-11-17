<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Locales;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;

#[Singleton]
final class ScopeJobLocaleListener
{
    private ?string $previous = null;

    public function handle(JobProcessing|JobProcessed|JobExceptionOccurred|JobAttempted $event): void
    {
        if ($event instanceof JobProcessing) {
            $this->enter();

            return;
        }

        $this->leave();
    }

    private function enter(): void
    {
        $this->previous ??= app()->getLocale();

        $locale = Context::getHidden(Locales::CONTEXT_KEY);

        Locales::apply(is_string($locale) ? $locale : (string) config('app.default_locale'));
    }

    private function leave(): void
    {
        if ($this->previous === null) {
            return;
        }

        Locales::apply($this->previous);
        $this->previous = null;
    }
}
