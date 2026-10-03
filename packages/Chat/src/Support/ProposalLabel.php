<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

final readonly class ProposalLabel
{
    /** @var list<string> */
    private const array TITLE_ROWS = ['Name', 'Title', 'Email'];

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $display
     */
    public static function of(string $entityType, array $data, array $display): ?string
    {
        $titleKey = ProposalCoreFields::titleKey($entityType);

        return self::filled($data[$titleKey] ?? null)
            ?? self::titleRow($display, $titleKey)
            ?? self::filled($display['summary'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $display
     */
    private static function titleRow(array $display, string $titleKey): ?string
    {
        $rows = is_array($display['fields'] ?? null) ? $display['fields'] : [];
        $labels = [...self::TITLE_ROWS, ...array_map(self::translated(...), self::TITLE_ROWS)];

        foreach ($rows as $row) {
            // A custom-field row carries its field code: a workspace field named "Email" is not the record's title.
            if (! is_array($row) || ($row['code'] ?? $titleKey) !== $titleKey || ! in_array($row['label'] ?? null, $labels, true)) {
                continue;
            }

            $value = self::filled($row['new'] ?? $row['value'] ?? $row['old'] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function translated(string $label): string
    {
        $translation = __($label);

        return is_string($translation) ? $translation : $label;
    }

    private static function filled(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
