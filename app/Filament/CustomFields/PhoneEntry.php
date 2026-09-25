<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use Filament\Infolists\Components\ViewEntry;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractInfolistEntry;
use Relaticle\CustomFields\Models\CustomField;

final class PhoneEntry extends AbstractInfolistEntry
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
        $countryOptions = config('custom-fields.phone.country_codes', []);
        $countryOptions = is_array($countryOptions) ? $countryOptions : [];

        return array_values(
            Collection::make($values)
                ->map(fn (mixed $entry): ?array => $this->item($entry, $countryOptions))
                ->filter(fn (?array $item): bool => $item !== null)
                ->values()
                ->all()
        );
    }

    /**
     * @param  array<string, mixed>  $countryOptions
     * @return array{display: string, href: string, copy: string, external: bool}|null
     */
    private function item(mixed $entry, array $countryOptions): ?array
    {
        if (is_string($entry) && $entry !== '') {
            return [
                'display' => $entry,
                'href' => 'tel:'.preg_replace('/[^0-9+]/', '', $entry),
                'copy' => $entry,
                'external' => false,
            ];
        }

        if (! is_array($entry) || blank($entry['number'] ?? null)) {
            return null;
        }

        $country = is_string($entry['country'] ?? null) ? $entry['country'] : 'US';
        $countryCode = is_string($countryOptions[$country] ?? null) ? $countryOptions[$country] : '+1';
        preg_match('/(\d+)/', $countryCode, $matches);
        $code = $matches[1] ?? '1';
        $number = preg_replace('/[^0-9]/', '', (string) $entry['number']);
        $display = $countryCode.' '.$entry['number'];

        return [
            'display' => $display,
            'href' => 'tel:+'.$code.$number,
            'copy' => $display,
            'external' => false,
        ];
    }
}
