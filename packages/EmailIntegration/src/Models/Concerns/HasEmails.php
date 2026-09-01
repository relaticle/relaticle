<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Relaticle\EmailIntegration\Models\Email;

trait HasEmails
{
    /**
     * @return MorphToMany<Email, $this, MorphPivot>
     */
    public function emails(): MorphToMany
    {
        return $this->morphToMany(Email::class, 'emailable')
            ->withPivot('link_source')
            ->withTimestamps()
            ->orderByDesc('sent_at');
    }
}
