<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Actions;

use App\Enums\Plan;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final readonly class RecordTrialChange
{
    /** @param  array<string, mixed>  $before */
    public function execute(SystemAdministrator $admin, Workspace $workspace, string $event, array $before): void
    {
        $old = array_map($this->serialize(...), $before);
        $new = array_map($this->serialize(...), $workspace->only(array_keys($before)));
        $changed = array_keys(array_diff_assoc($new, $old) + array_diff_assoc($old, $new));

        activity((string) config('activitylog.default_log_name'))
            ->causedBy($admin)
            ->performedOn($workspace)
            ->withProperties([
                'old' => Arr::only($old, $changed),
                'attributes' => Arr::only($new, $changed),
            ])
            ->event($event)
            ->log($event);
    }

    private function serialize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof CarbonInterface => $value->toDateTimeString(),
            $value instanceof Plan => $value->value,
            default => $value,
        };
    }
}
