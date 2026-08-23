<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

use App\Models\User;
use App\Support\TenantFkValidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Checks record references at proposal time, so a hallucinated or foreign id
 * fails as a tool error the model can correct, instead of rendering a blank
 * row on the card and failing only when the user approves.
 */
trait ValidatesOwnedForeignKeys
{
    /** @return array<string, class-string<Model>> */
    protected function ownedForeignKeys(): array
    {
        return [];
    }

    /** @return array<string, class-string<Model>> */
    protected function ownedForeignKeyLists(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function foreignKeyError(User $user, array $data): ?string
    {
        try {
            TenantFkValidator::assertOwned($user, $data, $this->ownedForeignKeys());
            TenantFkValidator::assertOwnedMany($user, $data, $this->ownedForeignKeyLists());
        } catch (ValidationException $exception) {
            return implode(' ', $exception->validator->errors()->all());
        }

        return null;
    }
}
