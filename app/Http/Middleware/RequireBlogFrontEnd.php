<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireBlogFrontEnd
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(filled(config('ink.views.preview')), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
