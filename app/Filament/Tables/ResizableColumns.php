<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Illuminate\Support\Js;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

final class ResizableColumns
{
    // Cell padding, the options button reserve, the type icon and the sort chevron take 94px,
    // so the floor keeps a few characters of the name beside them.
    public const int MIN_WIDTH = 120;

    public const int MAX_WIDTH = 1000;

    public const int DEFAULT_COLUMN_WIDTH = 180;

    /**
     * @param  array<string, int>  $widths
     * @param  list<string>  $pinned
     */
    public static function apply(Table $table, array $widths, array $pinned, string $title): Table
    {
        foreach ($table->getColumns() as $name => $column) {
            $width = $widths[$name] ?? self::pixels($column->getWidth()) ?? self::DEFAULT_COLUMN_WIDTH;

            self::stamp($column, $name, self::clamp($width), in_array($name, $pinned, true), $name === $title);
        }

        return $table;
    }

    public static function clamp(int $width): int
    {
        return (int) Number::clamp($width, self::MIN_WIDTH, self::MAX_WIDTH);
    }

    private static function pixels(string|int|null $width): ?int
    {
        return preg_match('/^(\d+)(px)?$/', (string) $width, $match) === 1 ? (int) $match[1] : null;
    }

    private static function stamp(Column $column, string $name, int $width, bool $isPinned, bool $isTitle): void
    {
        $min = self::MIN_WIDTH;
        $max = self::MAX_WIDTH;
        $columnId = (string) Str::of($name)->camel()->kebab();
        $style = "min-width: {$width}px; width: {$width}px; max-width: {$width}px";
        $attributes = [
            'data-resizable-width' => (string) $width,
            'data-resizable-column-id' => $columnId,
            'data-column-pinned' => $isPinned ? '1' : '0',
            // A phone card is headed by the title column, wherever the member moved or pinned it.
            ...($isTitle ? ['data-column-title' => '1'] : []),
        ];

        $column->extraHeaderAttributes([
            ...$attributes,
            'data-column-hideable' => $column->isToggleable() ? '1' : '0',
            'data-column-sortable' => $column->isSortable() ? '1' : '0',
            'x-data' => 'resizableColumn('.Js::from($name).', '.Js::from($columnId).", {$min}, {$max})",
            'class' => 'fi-ta-resizable-header',
            'style' => $style,
        ]);

        $column->extraCellAttributes([...$attributes, 'style' => "{$style}; overflow: hidden"]);

        // Filament appends the column's own width after the header style, where the last width wins.
        $column->width("{$width}px");
    }
}
