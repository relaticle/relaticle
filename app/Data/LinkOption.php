<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CrmEntity;
use Filament\Models\Contracts\HasAvatar;
use Illuminate\Database\Eloquent\Model;

final readonly class LinkOption
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $image = null,
        public ?string $icon = null,
        public bool $circular = true,
    ) {}

    public static function forRecord(Model $record, string $label, ?CrmEntity $entity = null): self
    {
        $image = $record instanceof HasAvatar ? $record->getFilamentAvatarUrl() : null;

        return new self(
            id: (string) $record->getKey(),
            label: $label,
            image: $image,
            icon: $image === null ? $entity?->iconPath() : null,
            circular: $entity !== CrmEntity::Company,
        );
    }

    /**
     * @return array{id: string, label: string, image: ?string, icon: ?string, circular: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'image' => $this->image,
            'icon' => $this->icon,
            'circular' => $this->circular,
        ];
    }
}
