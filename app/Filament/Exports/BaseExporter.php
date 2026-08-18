<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\CustomField;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Relaticle\CustomFields\Enums\FieldDataType;

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
        return self::timezoneFor($this->export->user);
    }

    /**
     * Header-row timezone, resolved from the session — see timezone() for why.
     */
    public static function requestTimezone(): string
    {
        return self::timezoneFor(Auth::guard('web')->user());
    }

    /**
     * The web guard is shared with the sysadmin panel's SystemAdministrator, and an
     * export row can outlive its author, so narrow to the customer model rather than
     * assuming either is present.
     */
    private static function timezoneFor(?Authenticatable $user): string
    {
        return $user instanceof User
            ? $user->effectiveTimezone()
            : (string) config('app.timezone');
    }

    /**
     * Every exporter builds its datetime columns through here, so a workspace never
     * ends up with one CSV in local time and the next in UTC. The zone is named in
     * the header because a bare timestamp gives the reader no way to tell which.
     */
    protected static function dateTimeColumn(string $name, string $label): ExportColumn
    {
        return ExportColumn::make($name)
            ->label($label.' ('.self::requestTimezone().')')
            ->formatStateUsing(fn (?Carbon $state, BaseExporter $exporter): ?string => $state?->setTimezone($exporter->timezone())->format('Y-m-d H:i:s'));
    }

    /**
     * The custom-field columns arrive from the package already built, so they never pass
     * through `dateTimeColumn()` above. Left alone they write the stored UTC under a bare
     * header, in the same file where the native columns say `(Asia/Tokyo)` — and the
     * neighbouring suffix is what makes that dangerous, because it tells the reader the
     * file is local when one column is not.
     *
     * Only `date-time` fields are converted. A date-only field has no time of day, so
     * shifting it would move the calendar day for every viewer west of UTC; it is instead
     * trimmed to `Y-m-d` to drop the `00:00:00` a Carbon cast invents.
     *
     * @param  Collection<int, ExportColumn>  $columns
     * @return array<int, ExportColumn>
     */
    protected static function customFieldColumns(Collection $columns): array
    {
        // Scoped to this exporter's entity: field codes are unique per entity type, not
        // globally, so `linkedin` exists on both Company and People. Keying an unscoped
        // list by code would let one entity's field decide another's formatting.
        $fieldsByName = CustomField::query()
            ->forEntity(self::getModel())
            ->get()
            ->keyBy(fn (CustomField $field): string => $field->getFieldName());

        return $columns
            ->map(function (ExportColumn $column) use ($fieldsByName): ExportColumn {
                $field = $fieldsByName->get($column->getName());

                if (! $field instanceof CustomField) {
                    return $column;
                }

                if ($field->typeData->dataType === FieldDataType::DATE_TIME) {
                    return $column
                        ->label($column->getLabel().' ('.self::requestTimezone().')')
                        ->formatStateUsing(fn (mixed $state, BaseExporter $exporter): mixed => $state instanceof Carbon
                            ? $state->copy()->setTimezone($exporter->timezone())->format('Y-m-d H:i:s')
                            : $state);
                }

                if ($field->typeData->dataType === FieldDataType::DATE) {
                    return $column->formatStateUsing(fn (mixed $state): mixed => $state instanceof Carbon
                        ? $state->format('Y-m-d')
                        : $state);
                }

                return $column;
            })
            ->all();
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
