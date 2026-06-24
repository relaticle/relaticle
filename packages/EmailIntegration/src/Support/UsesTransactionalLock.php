<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Serialises a critical section across processes with a Postgres transaction-level
 * advisory lock. Preferred over Cache::lock here: it is Postgres-native (no cache
 * driver/TTL to tune), and the lock is released automatically when the transaction
 * commits or rolls back, so a crashing worker can never strand it.
 */
trait UsesTransactionalLock
{
    /**
     * Run the callback inside a transaction holding an advisory lock keyed by an
     * arbitrary string. Concurrent callers with the same key block until the
     * holder's transaction ends.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function transactional(string $key, Closure $callback): mixed
    {
        return DB::transaction(function () use ($key, $callback) {
            // hashtextextended folds the arbitrary string key into the bigint the
            // advisory-lock API requires; the lock is held until this tx commits.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);

            return $callback();
        });
    }
}
