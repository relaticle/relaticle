<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Impersonation\StopImpersonationController;
use App\Support\Impersonation\Impersonator;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

final readonly class SuperviseImpersonation
{
    /**
     * @var list<string>
     */
    private const array CALLS_THAT_ONLY_RENDER = ['__lazyLoad', '$refresh', '$commit'];

    public function __construct(
        private Impersonator $impersonator,
        private StopImpersonationController $stopImpersonation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->impersonator->active($request) || $request->routeIs('impersonation.*')) {
            return $next($request);
        }

        $administrator = $this->impersonator->administrator($this->impersonator->administratorId($request));

        if ($this->impersonator->lapsed($request, $administrator)) {
            return $this->end($request);
        }

        $this->recordWrite($request, $administrator);

        return $next($request);
    }

    private function end(Request $request): RedirectResponse
    {
        if (! $request->hasHeader('X-Livewire') && ! $request->expectsJson()) {
            return ($this->stopImpersonation)($request);
        }

        $this->impersonator->stop($request);

        // A script cannot follow a redirect to another host, and Livewire offers a reload on 419.
        abort(419);
    }

    private function recordWrite(Request $request, (Model&Authenticatable)|null $administrator): void
    {
        if ($request->isMethodSafe() || $request->is('broadcasting/auth')) {
            return;
        }

        $actions = $this->livewireActions($request);

        if ($request->routeIs('*livewire.update') && $actions === []) {
            return;
        }

        $this->impersonator->record(
            'impersonation_request',
            $administrator,
            $request->user('web'),
            [
                'method' => $request->method(),
                'path' => $request->path(),
                'route' => $request->route()?->getName(),
                'actions' => $actions,
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function livewireActions(Request $request): array
    {
        return array_merge(...array_map(
            $this->componentActions(...),
            array_values(Arr::wrap($request->input('components'))),
        ));
    }

    /**
     * @return list<string>
     */
    private function componentActions(mixed $component): array
    {
        $snapshot = data_get($component, 'snapshot');
        $name = is_string($snapshot) ? data_get(json_decode($snapshot, true), 'memo.name') : null;

        if (! is_string($name)) {
            return [];
        }

        $actions = [];

        foreach (array_keys(Arr::wrap(data_get($component, 'updates'))) as $property) {
            $actions[] = "{$name}::\${$property}";
        }

        foreach (Arr::wrap(data_get($component, 'calls')) as $call) {
            $method = data_get($call, 'method');

            if (! is_string($method) || in_array($method, self::CALLS_THAT_ONLY_RENDER, true)) {
                continue;
            }

            $mounted = $method === 'mountAction' ? data_get($call, 'params.0') : null;

            $actions[] = is_string($mounted) ? "{$name}::{$method}({$mounted})" : "{$name}::{$method}";
        }

        return $actions;
    }
}
