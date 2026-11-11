<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CrmEntity;
use App\Models\User;
use Filament\Models\Contracts\HasAvatar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function selecting(Builder $query, string $titleColumn): Builder
    {
        $model = $query->getModel();
        $columns = [$model->getKeyName(), $titleColumn];

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $columns[] = 'deleted_at';
        }

        if ($model instanceof User) {
            $columns[] = 'profile_photo_path';
        }

        $query->select(array_map($model->qualifyColumn(...), array_unique($columns)));

        if ($model instanceof HasAvatar && $model instanceof HasMedia && defined($model::class.'::LOGO_MEDIA_COLLECTION')) {
            $query->with(['media' => fn ($media) => $media->where('collection_name', constant($model::class.'::LOGO_MEDIA_COLLECTION'))]);
        }

        return $query;
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
