<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Profile\SyncUserTimezone;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class SyncUserTimezoneController
{
    public function __construct(private SyncUserTimezone $syncUserTimezone) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
        ]);

        $user = $request->user();

        if (! $user instanceof User) {
            return new JsonResponse(['synced' => false], 403);
        }

        return new JsonResponse([
            'synced' => $this->syncUserTimezone->execute($user, $validated['timezone']),
        ]);
    }
}
