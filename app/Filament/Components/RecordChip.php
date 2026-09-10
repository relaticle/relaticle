<?php

declare(strict_types=1);

namespace App\Filament\Components;

use App\Models\Company;
use Filament\Models\Contracts\HasAvatar;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final readonly class RecordChip implements Htmlable
{
    public function __construct(
        public string $name,
        public ?string $avatarUrl = null,
        public bool $circular = true,
        public string $size = 'sm',
    ) {}

    public static function forRecord(Model $record, ?string $name = null, string $size = 'sm'): self
    {
        return new self(
            name: $name ?? (string) $record->getAttribute('name'),
            avatarUrl: $record instanceof HasAvatar ? $record->getFilamentAvatarUrl() : null,
            circular: ! $record instanceof Company,
            size: $size,
        );
    }

    /**
     * @return array<int, self>
     */
    public static function forRecords(mixed $records, string $size = 'sm'): array
    {
        if ($records instanceof Model) {
            return [self::forRecord($records, size: $size)];
        }

        if ($records instanceof Collection) {
            return $records
                ->filter(fn (mixed $record): bool => $record instanceof Model)
                ->map(fn (Model $record): self => self::forRecord($record, size: $size))
                ->values()
                ->all();
        }

        return [];
    }

    public function toHtml(): string
    {
        return trim(view('filament.components.record-chip', ['chip' => $this])->render());
    }
}
