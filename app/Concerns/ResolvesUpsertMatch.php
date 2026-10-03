<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Actions\CustomFields\FindEntitiesByFieldValue;
use App\Enums\CrmEntity;
use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFields\CanonicalValue;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Symfony\Component\HttpFoundation\Response;

trait ResolvesUpsertMatch
{
    // A string match value cannot be compared to boolean, numeric or date columns, and
    // single-choice fields store option keys, never the label a form submits.
    private const array MATCHABLE_DATA_TYPES = [
        FieldDataType::STRING,
        FieldDataType::TEXT,
        FieldDataType::MULTI_CHOICE,
    ];

    // Enough to show a caller which records to merge without hydrating every duplicate.
    private const int REPORTED_MATCH_LIMIT = 25;

    private bool $storesMatchValue = false;

    abstract protected function entity(): CrmEntity;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function whileHoldingMatch(Closure $callback): mixed
    {
        $field = $this->matchField();
        $value = $field instanceof CustomField ? $this->matchValues($field, $this->string('match.value')->toString())[0] : '';
        $key = implode(':', ['upsert', $this->workspaceId(), $this->entity()->value, $this->input('match.field'), mb_strtolower($value)]);

        try {
            // The callback resolves the match again: a concurrent upsert may have created the record since validation.
            return Cache::lock($key, 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            abort(503, 'Another request is upserting this record. Retry shortly.', ['Retry-After' => '1']);
        }
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $codes = $this->matchableFields()->keys()->implode(', ') ?: 'none';

        return ['match.field.in' => "The match.field must be a custom field marked unique: {$codes}."];
    }

    /** @return array<string, mixed> */
    public function upsertData(?Model $matched): array
    {
        $data = Arr::except($this->validated(), ['match']);

        // A record a concurrent upsert created since validation keeps its own values for the match field.
        if ($matched instanceof Model && $this->storesMatchValue) {
            Arr::forget($data, "custom_fields.{$this->string('match.field')}");
        }

        return $data;
    }

    protected function prepareForValidation(): void
    {
        $this->storeMatchValueOnCreate();

        parent::prepareForValidation();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function matchRules(): array
    {
        return [
            'match' => ['required', 'array'],
            'match.field' => ['required', 'string', Rule::in($this->matchableFields()->keys()->all())],
            'match.value' => ['required', 'string', 'max:255'],
        ];
    }

    protected function resolveMatch(): ?Model
    {
        $field = $this->matchField();
        $value = $this->input('match.value');

        // Resolved before validation runs, so input the rules would reject is skipped here.
        if (! $field instanceof CustomField || ! is_string($value)) {
            return null;
        }

        $matches = resolve(FindEntitiesByFieldValue::class)
            ->execute($this->entity()->model(), $field, $this->matchValues($field, $value), self::REPORTED_MATCH_LIMIT);

        // Uniqueness is checked on write only, case-sensitively and from the moment it is switched on,
        // so several records can still share a value.
        if ($matches->count() > 1) {
            throw new HttpResponseException(response()->json([
                'message' => "More than one record holds this {$field->code} value. Merge the duplicates, then retry.",
                'matches' => $matches->modelKeys(),
            ], Response::HTTP_CONFLICT));
        }

        return $matches->first();
    }

    // A required custom field the caller omitted is already answered by the matched record,
    // which the update action merges back in, so validation runs as an update.
    protected function existingRecord(): ?Model
    {
        return $this->resolveMatch();
    }

    protected function workspaceId(): string
    {
        /** @var User $user */
        $user = $this->user();

        return (string) $user->currentWorkspace->getKey();
    }

    // A created record carries the value it was matched on, or the same call creates it again.
    private function storeMatchValueOnCreate(): void
    {
        $field = $this->matchField();
        $value = $this->input('match.value');
        $customFields = $this->input('custom_fields') ?? [];

        if (! $field instanceof CustomField || ! is_string($value) || blank($value) || ! is_array($customFields)) {
            return;
        }

        if (array_key_exists($field->code, $customFields) || $this->resolveMatch() instanceof Model) {
            return;
        }

        $this->storesMatchValue = true;

        $this->merge(['custom_fields' => [
            ...$customFields,
            $field->code => $field->typeData->dataType->isMultiChoiceField() ? [trim($value)] : trim($value),
        ]]);
    }

    private function matchField(): ?CustomField
    {
        $code = $this->input('match.field');

        return is_string($code) ? $this->matchableFields()->get($code) : null;
    }

    // Rows written before values were normalized on every write can still hold the spelling as sent.
    /** @return array<int, string> */
    private function matchValues(CustomField $field, string $value): array
    {
        $value = trim($value);

        return array_values(array_unique([CanonicalValue::of($field, $value), $value]));
    }

    /** @return Collection<string, CustomField> */
    private function matchableFields(): Collection
    {
        return once(fn (): Collection => CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->workspaceId())
            ->where('entity_type', $this->entity()->value)
            ->active()
            ->get()
            ->filter(fn (CustomField $field): bool => $field->settings->unique_per_entity_type && in_array(
                CustomFieldsType::getFieldType($field->type)?->dataType,
                self::MATCHABLE_DATA_TYPES,
                true,
            ))
            ->keyBy(fn (CustomField $field): string => (string) $field->code));
    }
}
