<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Models\User;

/**
 * The templated welcome wording, shared by the action that writes the message
 * and the job that later refines it, so the two can never drift.
 */
final readonly class WelcomeCopy
{
    /**
     * A blank or whitespace-only name would render "Hi , welcome to Relaticle",
     * so fall back to a greeting that reads correctly without one.
     */
    public function firstName(User $owner): string
    {
        $first = explode(' ', trim($owner->name))[0];

        return $first === '' ? __('chat-welcome.default_name') : $first;
    }

    public function templated(User $owner): string
    {
        return __('chat-welcome.fallback', ['name' => $this->firstName($owner)]);
    }
}
