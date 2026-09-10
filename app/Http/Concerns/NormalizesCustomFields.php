<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Models\User;
use App\Support\CustomFields\CustomFieldInput;

trait NormalizesCustomFields
{
    abstract protected function customFieldEntityType(): string;

    protected function prepareForValidation(): void
    {
        if (! $this->has('custom_fields')) {
            return;
        }

        /** @var User $user */
        $user = $this->user();

        $this->merge([
            'custom_fields' => resolve(CustomFieldInput::class)->normalize(
                $user->currentTeam->getKey(),
                $this->customFieldEntityType(),
                $this->input('custom_fields'),
            ),
        ]);
    }
}
