<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Enums\CrmEntity;
use App\Models\User;
use App\Support\CustomFields\CustomFieldInput;

trait NormalizesCustomFields
{
    abstract protected function entity(): CrmEntity;

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
                $this->entity()->value,
                $this->input('custom_fields'),
            ),
        ]);
    }
}
