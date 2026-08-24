<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

use App\Models\User;
use App\Support\TenantFkValidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Relaticle\Chat\Services\Tools\PlanReferenceValidator;
use Relaticle\Chat\Support\PlanReference;

/**
 * Checks record references at proposal time, so a hallucinated or foreign id
 * fails as a tool error the model can correct, instead of rendering a blank
 * row on the card and failing only when the user approves.
 *
 * A `$ref:<pending_action_id>` value names a record an earlier step of the same
 * turn will create. There is nothing to look up yet, so it is checked against
 * that step's proposal (PlanReferenceValidator) and held out of the ownership
 * check, which would otherwise read it as a foreign id.
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
        $referenceError = $this->planReferenceError($user, $data);

        if ($referenceError !== null) {
            return $referenceError;
        }

        $data = $this->withoutPlanReferences($data);

        try {
            TenantFkValidator::assertOwned($user, $data, $this->ownedForeignKeys());
            TenantFkValidator::assertOwnedMany($user, $data, $this->ownedForeignKeyLists());
        } catch (ValidationException $exception) {
            return implode(' ', $exception->validator->errors()->all());
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function planReferenceError(User $user, array $data): ?string
    {
        $validator = resolve(PlanReferenceValidator::class);
        $conversationId = $this->resolveConversationId();
        $turnId = $this->resolveTurnId();

        foreach ($this->ownedForeignKeys() as $key => $modelClass) {
            $error = $validator->error($user, $data[$key] ?? null, $modelClass, $conversationId, $turnId);

            if ($error !== null) {
                return $error;
            }
        }

        foreach ($this->ownedForeignKeyLists() as $key => $modelClass) {
            $values = $data[$key] ?? null;

            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                $error = $validator->error($user, $value, $modelClass, $conversationId, $turnId);

                if ($error !== null) {
                    return $error;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutPlanReferences(array $data): array
    {
        foreach (array_keys($this->ownedForeignKeys()) as $key) {
            if (PlanReference::is($data[$key] ?? null)) {
                unset($data[$key]);
            }
        }

        foreach (array_keys($this->ownedForeignKeyLists()) as $key) {
            if (! is_array($data[$key] ?? null)) {
                continue;
            }

            $kept = array_values(array_filter(
                $data[$key],
                static fn (mixed $value): bool => ! PlanReference::is($value),
            ));

            if ($kept === []) {
                unset($data[$key]);

                continue;
            }

            $data[$key] = $kept;
        }

        return $data;
    }
}
