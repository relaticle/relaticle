<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Locales;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;

#[Singleton]
final class ScopeJobLocaleListener
{
    /** @var array<int, string> */
    private array $previous = [];

    public function handle(JobProcessing|JobAttempted $event): void
    {
        $key = spl_object_id($event->job);

        if ($event instanceof JobProcessing) {
            $this->enter($key);

            return;
        }

        $this->leave($key);
    }

    private function enter(int $key): void
    {
        $this->previous[$key] = app()->getLocale();

        $locale = Context::getHidden(Locales::CONTEXT_KEY);

        Locales::apply(is_string($locale) ? $locale : (string) config('app.default_locale'));
    }

    private function leave(int $key): void
    {
        if (! isset($this->previous[$key])) {
            return;
        }

        Locales::apply($this->previous[$key]);
        unset($this->previous[$key]);
    }
}
