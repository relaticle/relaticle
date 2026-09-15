<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class FailedStoreEmailImportService
{
    public function hasFailures(ConnectedAccount $account): bool
    {
        return $this->countFor($account) > 0;
    }

    public function countFor(ConnectedAccount $account): int
    {
        return count($this->uuidsFor($account));
    }

    /**
     * @return list<string>
     */
    public function uuidsFor(ConnectedAccount $account): array
    {
        $accountId = (string) $account->getKey();

        $uuids = DB::table('failed_jobs')
            ->where('payload', 'like', '%'.class_basename(StoreEmailJob::class).'%')
            ->where('payload', 'like', '%'.$accountId.'%')
            ->pluck('uuid')
            ->all();

        return array_values(array_map(static fn (mixed $uuid): string => (string) $uuid, $uuids));
    }

    public function retryAll(ConnectedAccount $account): int
    {
        $uuids = $this->uuidsFor($account);

        foreach ($uuids as $uuid) {
            Artisan::call('queue:retry', ['id' => $uuid]);
        }

        return count($uuids);
    }
}
