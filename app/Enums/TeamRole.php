<?php

declare(strict_types=1);

namespace App\Enums;

use Laravel\Jetstream\Jetstream;

enum TeamRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    /**
     * Falls back to the raw key for a legacy or unregistered role rather than
     * throwing. findRole()'s untyped return makes PHPStan misjudge `?->` here.
     */
    public static function label(string $role): string
    {
        $registeredRole = Jetstream::findRole($role);

        if ($registeredRole === null) {
            return $role;
        }

        return $registeredRole->name;
    }
}
