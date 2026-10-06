<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final readonly class ListQuery
{
    public const int MAX_PAGE = 1_000_000;

    /**
     * @param  array<int|string, mixed>|string|null  $include
     * @param  array<int|string, mixed>|string|null  $fields
     */
    public function __construct(
        public mixed $filter = null,
        public ?string $sort = null,
        public array|string|null $include = null,
        public array|string|null $fields = null,
        public int $perPage = 15,
        public ?int $page = null,
        public bool $cursor = false,
        public ?string $viewerZone = null,
    ) {}

    public function toRequest(): Request
    {
        return new Request(Arr::whereNotNull([
            'filter' => $this->filter,
            'sort' => $this->sort,
            'include' => $this->include,
            'fields' => $this->fields,
        ]));
    }
}
