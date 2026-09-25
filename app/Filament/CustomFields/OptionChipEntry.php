<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Models\CustomFieldOption;
use Filament\Infolists\Components\ViewEntry;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractInfolistEntry;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\CustomFields\Models\CustomField;

final class OptionChipEntry extends AbstractInfolistEntry
{
    public function make(CustomField $customField): ViewEntry
    {
        return ViewEntry::make($customField->getFieldName())
            ->label($customField->name)
            ->view('filament.infolists.components.multi-value-entry')
            ->state(fn (mixed $record): array => $this->items($record, $customField));
    }

    /**
     * @return list<array{display: string}>
     */
    private function items(mixed $record, CustomField $customField): array
    {
        if (! $record instanceof HasCustomFields) {
            return [];
        }

        $selected = $record->getCustomFieldValue($customField);
        $values = is_array($selected) ? $selected : (filled($selected) ? [$selected] : []);
        $labels = $customField->options
            ->mapWithKeys(function (mixed $option): array {
                if (! $option instanceof CustomFieldOption) {
                    return [];
                }

                $name = $option->name;

                if (! is_string($name) || $name === '') {
                    return [];
                }

                return [(string) $option->getKey() => $name];
            });

        return array_values(
            Collection::make($values)
                ->map(function (mixed $value) use ($labels): ?array {
                    $label = $labels->get((string) $value);

                    if (! is_string($label)) {
                        return null;
                    }

                    return ['display' => $label];
                })
                ->filter(fn (?array $item): bool => $item !== null)
                ->values()
                ->all()
        );
    }
}
