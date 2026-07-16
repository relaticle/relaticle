<?php

declare(strict_types=1);

namespace App\Support\Database;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Thin seam over Postgres advisory locks.
 *
 * Keeps the single driver-specific construct (`pg_advisory_xact_lock`) in one
 * place so call sites read as portable, intent-revealing code instead of raw
 * SQL. This is the only file that should reference the advisory-lock primitive.
 */
final readonly class AdvisoryLock
{
    /**
     * Run $callback while holding a transaction-scoped advisory lock keyed on $key.
     *
     * The lock is acquired inside a transaction and released automatically on
     * commit/rollback, so a concurrent caller using the same key blocks until
     * this one's writes are committed and visible — making a check-then-write
     * sequence atomic across parallel processes (e.g. queue workers).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function transactional(string $key, Closure $callback): mixed
    {
        return DB::transaction(function () use ($key, $callback) {
            // hashtextextended folds the arbitrary string key into the bigint the
            // advisory-lock API requires; the lock is held until this tx commits.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);

            return $callback();
        });
    }
}
