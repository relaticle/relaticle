<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Models\EmailTemplate;

final readonly class DeleteEmailTemplatesAction
{
    /**
     * Delete only the templates the user owns; shared templates created by
     * others are left untouched.
     *
     * @param  Collection<int, EmailTemplate>  $templates
     * @return int Number of templates deleted.
     */
    public function execute(User $user, Collection $templates): int
    {
        return $templates
            ->filter(fn (EmailTemplate $template): bool => $template->created_by === $user->getKey())
            ->each->delete()
            ->count();
    }
}
