<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Models\CustomField;
use App\Models\User;
use App\Rules\ValidCustomFields;
use App\Support\CustomFields\CustomFieldInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use Relaticle\Chat\Support\PlanReference;
use Relaticle\CustomFields\Data\RecordLinkPayload;
use Relaticle\CustomFields\Models\CustomFieldRelationship;

final readonly class CustomFieldsRequestValidator
{
    public function __construct(
        private CustomFieldInput $input,
        private PlanReferenceValidator $planReferences,
    ) {}

    /**
     * @param  string|int|null  $ignoreEntityId  the record being updated, excluded from unique-value checks
     */
    public function validate(
        User $user,
        string $entityType,
        mixed $rawCustomFields,
        bool $isUpdate = true,
        string|int|null $ignoreEntityId = null,
        ?string $viewerZone = null,
        ?string $conversationId = null,
        ?string $turnId = null,
    ): CustomFieldsValidationResult {
        $rawCustomFields = is_array($rawCustomFields) ? $rawCustomFields : [];

        // An update touches only the submitted codes; a create must also satisfy
        // every required field, so it runs the rules even with an empty payload.
        if ($rawCustomFields === [] && $isUpdate) {
            return new CustomFieldsValidationResult(cleanFields: [], error: null);
        }

        $workspaceId = $user->currentWorkspace->getKey();

        try {
            $clean = $this->input->normalize($workspaceId, $entityType, $rawCustomFields, $viewerZone);
        } catch (ValidationException $exception) {
            return new CustomFieldsValidationResult(
                cleanFields: [],
                error: $this->keyedMessages($exception->validator->errors()),
            );
        }

        $fields = $this->loadFields($workspaceId, $entityType, array_map(strval(...), array_keys($clean)));

        $referenceError = $this->planReferenceError($user, $clean, $fields, $conversationId, $turnId);

        if ($referenceError !== null) {
            return new CustomFieldsValidationResult(cleanFields: [], error: $referenceError);
        }

        $rules = new ValidCustomFields($workspaceId, $entityType, isUpdate: $isUpdate, ignoreEntityId: $ignoreEntityId)
            ->toRules($clean);

        // A reference stands for a record no step has created yet, so the field rules,
        // which ask the database what a value points at, are run without it. It is put
        // back into the stored payload: approval resolves it to the real id.
        $validator = Validator::make(['custom_fields' => $this->withoutPlanReferences($clean, $fields)], $rules);

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

    /**
     * @param  array<int, string>  $codes
     * @return Collection<int, CustomField>
     */
    private function loadFields(string $workspaceId, string $entityType, array $codes): Collection
    {
        if ($codes === []) {
            return new Collection;
        }

        /** @var Collection<int, CustomField> */
        return CustomField::query()
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', $entityType)
            ->active()
            ->whereIn('code', $codes)
            ->get();
    }

    /**
     * A link field may hold `$ref:<pending_action_id>` where a record proposed earlier in
     * this turn will be. It is checked exactly as a native foreign key is: same turn, a
     * still-pending create, of the entity this field points at.
     *
     * @param  array<string, mixed>  $fieldsPayload
     * @param  Collection<int, CustomField>  $fields
     */
    private function planReferenceError(User $user, array $fieldsPayload, Collection $fields, ?string $conversationId, ?string $turnId): ?string
    {
        $byCode = $fields->keyBy('code');

        foreach ($fieldsPayload as $code => $value) {
            $field = $byCode->get((string) $code);

            if (! $field instanceof CustomField) {
                continue;
            }

            $definition = $field->relationshipDefinition();

            if (! $definition instanceof CustomFieldRelationship) {
                continue;
            }

            $modelClass = Relation::getMorphedModel($definition->targetEntityTypeFor($field));

            if ($modelClass === null || ! is_subclass_of($modelClass, Model::class)) {
                return "custom_fields.{$code} points at a record type this workspace does not have.";
            }

            foreach ($this->references($value) as $reference) {
                $error = $this->planReferences->error($user, $reference, $modelClass, $conversationId, $turnId);

                if ($error !== null) {
                    return "custom_fields.{$code}: {$error}";
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $fieldsPayload
     * @param  Collection<int, CustomField>  $fields
     * @return array<string, mixed>
     */
    private function withoutPlanReferences(array $fieldsPayload, Collection $fields): array
    {
        $byCode = $fields->keyBy('code');

        foreach ($fieldsPayload as $code => $value) {
            $field = $byCode->get((string) $code);

            if (! $field instanceof CustomField || ! $field->relationshipDefinition() instanceof CustomFieldRelationship) {
                continue;
            }

            if ($this->references($value) === []) {
                continue;
            }

            $kept = array_values(array_filter(
                RecordLinkPayload::fromValue($value)->ids,
                static fn (mixed $id): bool => ! PlanReference::is($id),
            ));

            $fieldsPayload[$code] = $kept;
        }

        return $fieldsPayload;
    }

    /**
     * @return list<string>
     */
    private function references(mixed $value): array
    {
        return array_values(array_filter(
            array_map(strval(...), RecordLinkPayload::fromValue($value)->ids),
            PlanReference::is(...),
        ));
    }
}
