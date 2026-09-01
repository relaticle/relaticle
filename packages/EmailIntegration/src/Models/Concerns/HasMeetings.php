<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Relaticle\EmailIntegration\Models\Meeting;

trait HasMeetings
{
    /**
     * @return MorphToMany<Meeting, $this, MorphPivot>
     */
    public function meetings(): MorphToMany
    {
        return $this->morphToMany(Meeting::class, 'meetingable')
            ->withPivot('link_source')
            ->withTimestamps();
    }
}
