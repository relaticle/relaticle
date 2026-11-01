<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use App\Models\Workspace;
use Relaticle\Chat\Enums\WebSearchPreference;
use RuntimeException;

final readonly class GrantWebAccess
{
    public function execute(User $user, Workspace $workspace): void
    {
        throw_unless($workspace->ai_web_search_enabled, RuntimeException::class, __('Your workspace owner turned web search off.'));

        throw_if(
            WebSearchPreference::of($user) === WebSearchPreference::Deny,
            RuntimeException::class,
            __('You turned web search off in your settings.'),
        );
    }
}
