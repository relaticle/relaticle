<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Concerns;

use App\Support\CustomFields\RecordNameResolver;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

trait PrimesRecordNames
{
    public static function collection(mixed $resource): AnonymousResourceCollection
    {
        resolve(RecordNameResolver::class)->prime($resource instanceof Paginator ? $resource->items() : $resource);

        return parent::collection($resource);
    }
}
