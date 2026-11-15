<?php

declare(strict_types=1);

namespace App\Support\ActivityLog;

use App\Models\ActivityLog\Activity;
use App\Support\CurrentSource;
use App\Support\Impersonation\Impersonator;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Relaticle\ImportWizard\Models\Import;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;

final readonly class StampsActivity
{
    public static function apply(ActivityContract $activity): void
    {
        if (! $activity instanceof Activity) {
            return;
        }

        if (blank($activity->getAttribute('batch_uuid'))) {
            $activity->setAttribute('batch_uuid', resolve(RequestActivityBatch::class)->id());
        }

        $import = resolve(CurrentImport::class);

        if ($import->id() !== null && $activity->getAttribute('subject_type') !== Relation::getMorphAlias(Import::class)) {
            $activity->properties = ($activity->properties ?? new Collection)
                ->put('import_id', $import->id())
                ->put('import_file', $import->fileName());
        }

        $activity->properties = ($activity->properties ?? new Collection)
            ->put(Activity::SOURCE_PROPERTY, CurrentSource::get()->value);

        $administratorId = resolve(Impersonator::class)->administratorId(request())
            ?? Context::getHidden('impersonated_by');

        if (is_string($administratorId)) {
            $activity->properties = ($activity->properties ?? new Collection)
                ->put('impersonated_by', $administratorId);
        }
    }
}
