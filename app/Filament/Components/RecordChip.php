<?php

declare(strict_types=1);

namespace App\Filament\Components;

use App\Enums\CrmEntity;
use Filament\Models\Contracts\HasAvatar;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A record rendered as one avatar-and-name pill.
 *
 * Three ways to draw the leading mark, in order: the record's own image, the
 * shared entity icon for a record type that has no per-record image worth
 * inventing (a company with no logo), and a name-derived initials tile for a
 * person, whose hue is the only thing telling two of them apart.
 */
final readonly class RecordChip implements Htmlable
{
    public function __construct(
        public string $name,
        public ?string $imageUrl = null,
        public ?string $iconPath = null,
        public bool $circular = true,
        public string $size = 'sm',
    ) {}

    public static function forRecord(Model $record, ?string $name = null, string $size = 'sm'): self
    {
        $entity = CrmEntity::tryFromModel($record);
        $image = $record instanceof HasAvatar ? $record->getFilamentAvatarUrl() : null;

        return new self(
            name: $name ?? (string) $record->getAttribute('name'),
            imageUrl: $image,
            iconPath: $image === null ? $entity?->iconPath() : null,
            circular: $entity !== CrmEntity::Company,
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
