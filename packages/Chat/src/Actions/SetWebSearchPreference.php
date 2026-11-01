<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use Relaticle\Chat\Enums\WebSearchPreference;

final readonly class SetWebSearchPreference
{
    public function execute(User $user, WebSearchPreference $preference): void
    {
        $user->update([
            'ai_preferences' => [...$user->ai_preferences ?? [], 'web_search' => $preference->value],
        ]);
    }
}
