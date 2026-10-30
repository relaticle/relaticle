<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Illuminate\Support\Js;
use Illuminate\Support\Str;

final class ResizableColumns
{
    public const int MIN_WIDTH = 40;

    public const int MAX_WIDTH = 1000;

    private const int DEFAULT_COLUMN_WIDTH = 180;

    public static function apply(Table $table): Table
    {
        $livewire = $table->getLivewire();

        if (! method_exists($livewire, 'getResizableColumnWidths')) {
            return $table;
        }

        $widths = $livewire->getResizableColumnWidths();
        $pinned = method_exists($livewire, 'getPinnedTableColumns') ? $livewire->getPinnedTableColumns() : [];

        foreach ($table->getColumns() as $name => $column) {
            self::stamp($column, $name, $widths[$name] ?? self::pixels($column->getWidth()) ?? self::DEFAULT_COLUMN_WIDTH, in_array($name, $pinned, true));
        }

        return $table;
    }

    private static function pixels(string|int|null $width): ?int
    {
        return preg_match('/^(\d+)(px)?$/', (string) $width, $match) === 1 ? (int) $match[1] : null;
    }

    private static function stamp(Column $column, string $name, int $width, bool $isPinned): void
    {
        $min = self::MIN_WIDTH;
        $max = self::MAX_WIDTH;
        $columnId = (string) Str::of($name)->camel()->kebab();
        $style = "min-width: {$width}px; width: {$width}px; max-width: {$width}px";
        $attributes = [
            'data-resizable-width' => (string) $width,
            'data-resizable-column-id' => $columnId,
            'data-column-pinned' => $isPinned ? '1' : '0',
        ];

        $column->extraHeaderAttributes([
            ...$attributes,
            'data-column-hideable' => $column->isToggleable() ? '1' : '0',
            'x-data' => 'resizableColumn('.Js::from($name).', '.Js::from($columnId).", {$min}, {$max})",
            'class' => 'fi-ta-resizable-header',
            'style' => $style,
        ]);

        $column->extraCellAttributes([...$attributes, 'style' => "{$style}; overflow: hidden"]);

        // Filament appends the column's own width after the header style, where the last width wins.
        $column->width("{$width}px");
    }
}
