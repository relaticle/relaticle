<?php

declare(strict_types=1);

namespace App\Support\Workspaces;

use App\Support\CurrentWorkspace;
use Closure;

final readonly class BindWorkspace
{
    public function __construct(private string $workspaceId) {}

    public function handle(object $job, Closure $next): mixed
    {
        return resolve(CurrentWorkspace::class)->within($this->workspaceId, fn (): mixed => $next($job));
    }
}
