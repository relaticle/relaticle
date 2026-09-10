<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Models\User;
use App\Rules\ValidCustomFields;
use App\Support\CustomFields\CustomFieldInput;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;

final readonly class CustomFieldsRequestValidator
{
    public function __construct(
        private CustomFieldInput $input,
    ) {}

    /**
     * @param  string|int|null  $ignoreEntityId  the record being updated, excluded from unique-value checks
     */
    public function validate(User $user, string $entityType, mixed $rawCustomFields, bool $isUpdate = true, string|int|null $ignoreEntityId = null): CustomFieldsValidationResult
    {
        $rawCustomFields = is_array($rawCustomFields) ? $rawCustomFields : [];

        // An update touches only the submitted codes; a create must also satisfy
        // every required field, so it runs the rules even with an empty payload.
        if ($rawCustomFields === [] && $isUpdate) {
            return new CustomFieldsValidationResult(cleanFields: [], error: null);
        }

        $teamId = $user->currentTeam->getKey();

        try {
            $normalized = $this->input->normalize($teamId, $entityType, $rawCustomFields);
        } catch (ValidationException $exception) {
            return new CustomFieldsValidationResult(
                cleanFields: [],
                error: $this->keyedMessages($exception->validator->errors()),
            );
        }

        $clean = is_array($normalized) ? $normalized : $rawCustomFields;

        $rules = new ValidCustomFields($teamId, $entityType, isUpdate: $isUpdate, ignoreEntityId: $ignoreEntityId)
            ->toRules($clean);

        $validator = Validator::make(['custom_fields' => $clean], $rules);

        if ($validator->fails()) {
            return new CustomFieldsValidationResult(
                cleanFields: [],
                error: 'custom_fields validation failed: '.$this->keyedMessages($validator->errors()),
            );
        }

        return new CustomFieldsValidationResult(cleanFields: $clean, error: null);
    }

    // The assistant retries by field code, so the key has to survive into the message.
    private function keyedMessages(MessageBag $errors): string
    {
        return collect($errors->messages())
            ->flatMap(fn (array $messages, string $key): array => array_map(
                fn (string $message): string => "{$key}: {$message}",
                $messages,
            ))
            ->implode('; ');
    }
}
