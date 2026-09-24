<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\CreationSource;
use App\Support\CurrentSource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetCurrentSource
{
    public function handle(Request $request, Closure $next, string $source): Response
    {
        CurrentSource::set(CreationSource::from($source));

        return $next($request);
    }
}
