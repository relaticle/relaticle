<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ReadAcrossWorkspaces
{
    public function __construct(private CurrentWorkspace $currentWorkspace) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->currentWorkspace->readAcrossWorkspaces();

        return $next($request);
    }
}
