<?php

declare(strict_types=1);

namespace App\Filament\Support\InlineField;

use App\Enums\CustomFieldType;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;

final readonly class InlineField
{
    private function __construct(
        public string $code,
        public bool $custom,
        public InlineCommit $commit,
        public string $label,
    ) {}

    /**
     * @param  class-string  $modelClass
     */
    public static function tryFor(string $modelClass, string $code): ?self
    {
        foreach (self::for($modelClass) as $field) {
            if ($field->code === $code) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param  class-string  $modelClass
     * @return list<self>
     */
    public static function for(string $modelClass): array
    {
        $fields = [];

        foreach (self::nativeCommits($modelClass) as $code => $commit) {
            $fields[] = new self($code, false, $commit, self::nativeLabel($code));
        }

        foreach (self::customFields($modelClass) as $customField) {
            $field = self::fromCustomField($customField);

            if ($field instanceof self) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    public static function fromCustomField(CustomField $customField): ?self
    {
        $type = CustomFieldType::tryFrom($customField->type);

        if (! $type instanceof CustomFieldType) {
            return null;
        }

        $commit = InlineCommit::forType($type);

        if (! $commit instanceof InlineCommit) {
            return null;
        }

        return new self(
            $customField->code,
            true,
            $commit,
            filled($customField->name) ? (string) $customField->name : $customField->code,
        );
    }

    public function isCustom(): bool
    {
        return $this->custom;
    }

    /**
     * @param  class-string  $modelClass
     * @return array<string, InlineCommit>
     */
    private static function nativeCommits(string $modelClass): array
    {
        return match ($modelClass) {
            Company::class => [
                'name' => InlineCommit::OnEnterOrBlur,
                'account_owner_id' => InlineCommit::OnChange,
            ],
            People::class => [
                'name' => InlineCommit::OnEnterOrBlur,
                'company_id' => InlineCommit::OnChange,
            ],
            Opportunity::class => [
                'name' => InlineCommit::OnEnterOrBlur,
                'company_id' => InlineCommit::OnChange,
                'contact_id' => InlineCommit::OnChange,
            ],
            default => [],
        };
    }

    private static function nativeLabel(string $code): string
    {
        return match ($code) {
            'name' => __('filament/inline-edit.fields.name'),
            'account_owner_id' => __('filament/inline-edit.fields.account_owner_id'),
            'company_id' => __('filament/inline-edit.fields.company_id'),
            'contact_id' => __('filament/inline-edit.fields.contact_id'),
            default => $code,
        };
    }

    /**
     * @param  class-string  $modelClass
     * @return list<CustomField>
     */
    private static function customFields(string $modelClass): array
    {
        return array_values(CustomField::query()
            ->forEntity($modelClass)
            ->get()
            ->all());
    }
}
