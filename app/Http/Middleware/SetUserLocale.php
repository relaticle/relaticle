<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CurrentWorkspace;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetUserLocale
{
    public function __construct(private CurrentWorkspace $currentWorkspace) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isStaffRequest()) {
            Locales::apply((string) config('app.default_locale'));
            Context::forgetHidden(Locales::CONTEXT_KEY);

            return $next($request);
        }

        $locale = $this->resolve($request);

        Locales::apply($locale);
        Context::addHidden(Locales::CONTEXT_KEY, $locale);

        return $next($request);
    }

    private function isStaffRequest(): bool
    {
        return Auth::getDefaultDriver() === 'sysadmin'
            || $this->currentWorkspace->readsAcrossWorkspaces()
            || (! config('app.sysadmin_domain') && $this->isUnderAdminPath());
    }

    private function isUnderAdminPath(): bool
    {
        $adminPath = '/'.trim((string) config('app.sysadmin_path'), '/');

        if ($adminPath === '/') {
            return false;
        }

        $path = '/'.ltrim($this->requestPath(), '/');

        return $path === $adminPath || str_starts_with($path, $adminPath.'/');
    }

    private function requestPath(): string
    {
        if (Livewire::isLivewireRequest() && ! is_string(request('components.0.snapshot'))) {
            return request()->path();
        }

        return Livewire::originalPath();
    }

    private function resolve(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $user->effectiveLocale();
        }

        return Locales::fromRequest($request) ?? (string) config('app.default_locale');
    }
}
