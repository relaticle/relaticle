<?php

declare(strict_types=1);

namespace App\Casts;

use App\Data\BoardStateData;
use App\Data\TableStateData;
use App\Enums\SavedViewType;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<TableStateData|BoardStateData, TableStateData|BoardStateData>
 */
final readonly class ViewStateCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): TableStateData|BoardStateData
    {
        $payload = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : [];
        $type = $attributes['type'] ?? SavedViewType::Table->value;

        return $type === SavedViewType::Board->value ? BoardStateData::from($payload) : TableStateData::from($payload);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        throw_if(! $value instanceof TableStateData && ! $value instanceof BoardStateData, InvalidArgumentException::class, 'A view state is a table state or a board state.');

        return $value->toJson();
    }
}
