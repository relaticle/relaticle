<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Every datetime column is `timestamp without time zone` holding a UTC value, so the
 * PostgreSQL session timezone decides what `now()` and any `timestamptz` cast mean. Left
 * unpinned it inherits the server default, which differs per environment and silently writes
 * local wall-clock into a UTC column.
 */
it('declares the utc session timezone on the pgsql connection so it does not inherit the server default', function (): void {
    expect(config('database.connections.pgsql.timezone'))->toBe('UTC');
});

it('pins the postgres session timezone to utc so the database clock cannot drift from the app clock', function (): void {
    expect(DB::select('show timezone')[0]->TimeZone)->toBe('UTC');
});

it('casts now() to a timestamp without shifting it, so a database-clock write lands in utc', function (): void {
    $row = DB::select("select cast(now() as timestamp) as cast_ts, (now() at time zone 'UTC') as utc_ts")[0];

    expect($row->cast_ts)->toBe($row->utc_ts);
});

it('keeps the database clock and the php clock on the same wall time', function (): void {
    $databaseNow = Date::parse((string) DB::select('select cast(now() as timestamp) as ts')[0]->ts);

    expect(abs(now()->diffInSeconds($databaseNow)))->toBeLessThan(60);
});
