<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Team;
use App\Models\User;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

abstract class BaseExporter extends Exporter
{
    /**
     * @param  array<string, string>  $columnMap
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        Export $export,
        array $columnMap,
        array $options,
    ) {
        parent::__construct($export, $columnMap, $options);

        // Set the team_id on the export record
        if (Auth::guard('web')->check() && Auth::guard('web')->user()->currentTeam) {
            /** @var Team $currentTeam */
            $currentTeam = Auth::guard('web')->user()->currentTeam;
            $export->team_id = $currentTeam->getKey();
        }
    }

    /**
     * A CSV is opened by a human who thinks in local time, so datetimes are converted
     * out of UTC before they are written.
     *
     * Values and headers resolve the same user from two different places because they
     * are produced at two different moments: `formatStateUsing` runs inside the queued
     * job, which has no session and reads the user off the export record, while
     * `getColumns()` is static and only ever evaluated for labels during the interactive
     * column-mapping step — Filament freezes those labels into the export's columnMap
     * and writes the header row from that, never re-deriving it in the job.
     */
    public function timezone(): string
    {
        $user = $this->export->user;

        return ($user instanceof User ? $user->timezone : null) ?? (string) config('app.timezone');
    }

    /**
     * Header-row timezone, resolved from the session — see timezone() for why.
     */
    public static function requestTimezone(): string
    {
        $user = Auth::guard('web')->user();

        return ($user instanceof User ? $user->timezone : null) ?? (string) config('app.timezone');
    }

    /**
     * Make exports tenant-aware by scoping to the current team
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function modifyQuery(Builder $query): Builder
    {
        if (Auth::guard('web')->check() && Auth::guard('web')->user()->currentTeam) {
            /** @var Team $currentTeam */
            $currentTeam = Auth::guard('web')->user()->currentTeam;

            return $query->where('team_id', $currentTeam->getKey());
        }

        return $query;
    }
}
