<?php

declare(strict_types=1);

namespace App\Features;

use App\Models\User;

final readonly class TaskDigestEmails
{
    public function resolve(User $user): bool
    {
        $percentage = (int) config('relaticle.features.task_digest_rollout_percentage', 0);

        if ($percentage <= 0) {
            return false;
        }

        if ($percentage >= 100) {
            return true;
        }

        return (abs(crc32((string) $user->getKey())) % 100) < $percentage;
    }
}
