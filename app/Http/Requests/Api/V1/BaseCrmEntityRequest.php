<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\CrmEntity;
use App\Models\User;
use App\Rules\ValidCustomFields;
use App\Support\CustomFields\CustomFieldInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

abstract class BaseCrmEntityRequest extends FormRequest
{
    abstract protected function entity(): CrmEntity;

    /**
     * @return array<string, array<int, mixed>>
     */
    abstract protected function entityRules(User $user): array;

    /**
     * @return array<string, array<int, mixed>>
     */
    final public function rules(): array
    {
        $user = $this->authenticatedUser();
        $record = $this->routeRecord();

        return array_merge($this->entityRules($user), new ValidCustomFields(
            $user->currentTeam->getKey(),
            $this->entity()->value,
            isUpdate: $record instanceof Model,
            ignoreEntityId: $record?->getKey(),
        )->toRules($this->input('custom_fields')));
    }

    protected function prepareForValidation(): void
    {
        $customFields = $this->input('custom_fields');

        if (! is_array($customFields)) {
            return;
        }

        $this->merge([
            'custom_fields' => resolve(CustomFieldInput::class)->normalize(
                $this->authenticatedUser()->currentTeam->getKey(),
                $this->entity()->value,
                $customFields,
            ),
        ]);
    }

    private function authenticatedUser(): User
    {
        /** @var User */
        return $this->user();
    }

    private function routeRecord(): ?Model
    {
        return collect($this->route()?->parameters() ?? [])
            ->first(fn (mixed $parameter): bool => $parameter instanceof Model);
    }
}
