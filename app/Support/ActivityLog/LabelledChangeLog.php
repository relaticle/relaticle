<?php

declare(strict_types=1);

namespace App\Support\ActivityLog;

use App\Models\ActivityLog\Activity;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\ActivityLogStatus;
use Spatie\Activitylog\Support\CauserResolver;
use Spatie\Activitylog\Support\Config;

final readonly class LabelledChangeLog
{
    public const string EVENT = 'custom_field_changes';

    private const int INSERT_CHUNK = 1000;

    /**
     * @param  array{value: mixed, label: string}  $old
     * @param  array{value: mixed, label: string}  $new
     */
    public static function write(Model $record, string $code, string $label, string $type, array $old, array $new): void
    {
        activity((string) config('activitylog.default_log_name'))
            ->performedOn($record)
            ->causedBy(auth()->user())
            ->withProperties(self::properties($code, $label, $type, $old, $new))
            ->event(self::EVENT)
            ->log(self::EVENT);
    }

    /**
     * @param  iterable<array{record: Model, code: string, label: string, type: string, old: array{value: mixed, label: string}, new: array{value: mixed, label: string}}>  $changes
     */
    public static function writeMany(iterable $changes): void
    {
        if (resolve(ActivityLogStatus::class)->disabled()) {
            return;
        }

        $template = self::template();
        $now = $template->fromDateTime(now());
        $rows = [];

        foreach ($changes as $change) {
            $properties = $template->properties->all();
            $record = $change['record'];

            $rows[] = [
                'workspace_id' => Activity::workspaceIdFor($record),
                'log_name' => $template->log_name,
                'description' => self::EVENT,
                'subject_type' => $record->getMorphClass(),
                'subject_id' => $record->getKey(),
                'event' => self::EVENT,
                'causer_type' => $template->causer_type,
                'causer_id' => $template->causer_id,
                'attribute_changes' => '[]',
                'properties' => json_encode([...self::properties($change['code'], $change['label'], $change['type'], $change['old'], $change['new']), ...$properties]),
                'batch_uuid' => $template->batch_uuid,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            Config::activityModel()::query()->insert($chunk);
        }
    }

    private static function template(): Activity
    {
        $template = new Activity;
        $template->log_name = (string) config('activitylog.default_log_name');
        $template->event = self::EVENT;
        $template->properties = collect();
        $template->causer()->associate(resolve(CauserResolver::class)->resolve(auth()->user()));

        StampsActivity::apply($template);

        return $template;
    }

    /**
     * @param  array{value: mixed, label: string}  $old
     * @param  array{value: mixed, label: string}  $new
     * @return array<string, list<array<string, mixed>>>
     */
    private static function properties(string $code, string $label, string $type, array $old, array $new): array
    {
        return [
            self::EVENT => [[
                'code' => $code,
                'label' => $label,
                'type' => $type,
                'old' => $old,
                'new' => $new,
            ]],
        ];
    }
}
