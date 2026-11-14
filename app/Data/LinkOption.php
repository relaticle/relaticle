<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CrmEntity;
use App\Models\User;
use Closure;
use Filament\Models\Contracts\HasAvatar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;

final readonly class LinkOption
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $image = null,
        public ?string $icon = null,
        public bool $circular = true,
    ) {}

    /** @return list<string> */
    public static function columns(Model $model, string $titleColumn): array
    {
        $columns = [$model->getKeyName(), $titleColumn];

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $columns[] = 'deleted_at';
        }

        if ($model instanceof User) {
            $columns[] = 'profile_photo_path';
        }

        return array_map($model->qualifyColumn(...), array_values(array_unique($columns)));
    }

    /** @return array<string, Closure(Relation<*, *, *>): mixed> */
    public static function marks(Model $model): array
    {
        if (! $model instanceof HasAvatar || ! $model instanceof HasMedia || ! defined($model::class.'::LOGO_MEDIA_COLLECTION')) {
            return [];
        }

        return ['media' => fn (Relation $media): Relation => $media->where('collection_name', constant($model::class.'::LOGO_MEDIA_COLLECTION'))];
    }

    public static function forRecord(Model $record, string $label, ?CrmEntity $entity = null): self
    {
        $image = $record instanceof HasAvatar ? $record->getFilamentAvatarUrl() : null;

        return new self(
            id: (string) $record->getKey(),
            label: $label,
            image: $image,
            icon: $entity?->iconPath(),
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
