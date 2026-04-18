<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

trait LogsCrmActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly([
                'updated_at',
                'last_email_at',
                'last_interaction_at',
                'email_count',
                'inbound_email_count',
                'outbound_email_count',
                'avg_response_time_hours',
            ])
            ->dontLogEmptyChanges();
    }
}
