<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Enums\CustomFieldType;
use Illuminate\Support\Str;
use Relaticle\CustomFields\FieldTypeSystem\BaseFieldType;
use Relaticle\CustomFields\FieldTypeSystem\Definitions\LinkFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;
use Relaticle\CustomFields\Filament\Integration\Components\Forms\LinkComponent;
use Relaticle\CustomFields\Filament\Integration\Components\Infolists\LinkEntry;
use Relaticle\CustomFields\Filament\Integration\Components\Tables\Columns\LinkColumn;
use Relaticle\CustomFields\Models\CustomField;

final class DomainFieldType extends BaseFieldType
{
    private const string WHITESPACE = '[\s\x{00A0}\x{200B}\x{FEFF}\x{3000}]';

    public function configure(): FieldSchema
    {
        return FieldSchema::multiChoice()
            ->key(CustomFieldType::DOMAIN->value)
            ->label(__('workspaces.custom_field_types.domain'))
            ->icon('heroicon-o-globe-alt')
            ->formComponent(LinkComponent::class)
            ->tableColumn(LinkColumn::class)
            ->infolistEntry(LinkEntry::class)
            ->priority(61)
            ->supportsMultiValue()
            ->supportsUniqueConstraint()
            ->withArbitraryValues()
            ->withoutUserOptions()
            ->systemOnly()
            ->defaultItemValidationRules(['max:2048', 'regex:/^(https?:\/\/)?([a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}(\/.*)?$/']);
    }

    public function setValue(string $value): string
    {
        $authority = Str::of($value)
            ->lower()
            ->replaceMatches('#'.self::WHITESPACE.'+#u', '')
            ->replaceMatches('#^[a-z][a-z0-9+.-]*://#', '')
            ->before('/')
            ->before('?')
            ->before('#')
            ->replaceMatches('#^.*@#', '')
            ->before(':');

        $host = (string) $authority->replaceMatches('#^(www\.)+#', '')->rtrim('.');

        if ($host === '' || str_contains($host, '.')) {
            return $host;
        }

        if ($authority->startsWith('www.')) {
            return "www.{$host}";
        }

        $unwrapped = (string) preg_replace('#^(?:https?://'.self::WHITESPACE.'*)+#iu', '', trim($value));

        return $unwrapped === $value ? $value : $this->setValue($unwrapped);
    }

    /**
     * @return list<string>
     */
    public function equivalentValues(string $value, CustomField $customField): array
    {
        return array_values(array_unique([
            $this->setValue($value),
            ...(new LinkFieldType)->equivalentValues($value, $customField),
        ]));
    }
}
