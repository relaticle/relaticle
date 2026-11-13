<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Analytics\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Date;
use Relaticle\Cloud\Analytics\Models\Acquisition;

final readonly class RecordAcquisition
{
    /** @param array{source: string, medium: ?string, campaign: ?string, landing: string, captured_at: int}|null $attribution */
    public function execute(User $user, ?array $attribution): void
    {
        $attribution = $attribution !== null && $attribution['captured_at'] >= now()->subDays(30)->getTimestamp()
            ? $attribution : null;

        Acquisition::query()->firstOrCreate(['user_id' => $user->getKey()], [
            'source' => $attribution['source'] ?? 'unknown',
            'medium' => $attribution['medium'] ?? null,
            'campaign' => $attribution['campaign'] ?? null,
            'landing' => $attribution['landing'] ?? 'other',
            'captured_at' => $attribution !== null ? Date::createFromTimestampUTC($attribution['captured_at']) : now(),
        ]);
    }
}
