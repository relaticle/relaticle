<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use Filament\Infolists\Components\ViewEntry;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractInfolistEntry;
use Relaticle\CustomFields\Models\CustomField;

final class EmailEntry extends AbstractInfolistEntry
{
    public function make(CustomField $customField): ViewEntry
    {
        return ViewEntry::make($customField->getFieldName())
            ->label($customField->name)
            ->view('filament.infolists.components.multi-value-entry')
            ->state(fn (mixed $record): array => $this->items($record->getCustomFieldValue($customField)));
    }

    /**
     * @return list<array{display: string, href: string, copy: string, external: bool}>
     */
    private function items(mixed $state): array
    {
        $values = is_array($state) ? $state : (filled($state) ? [$state] : []);

        return array_values(
            Collection::make($values)
                ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
                ->values()
                ->map(fn (string $value): array => [
                    'display' => $value,
                    'href' => 'mailto:'.$value,
                    'copy' => $value,
                    'external' => false,
                ])
                ->all()
        );
    }
}
