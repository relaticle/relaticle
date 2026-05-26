<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class ArrayExistsForTeam implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, true>|null */
    private ?array $validIds = null;

    public function __construct(
        private readonly string $table,
        private readonly string $arrayKey,
        private readonly int|string $teamId,
        private readonly string $column = 'id',
    ) {}

    /** @param array<string, mixed> $data */
    public function setData(array $data): static
    {
        if (Arr::get($this->data, $this->arrayKey) !== Arr::get($data, $this->arrayKey)) {
            $this->validIds = null;
        }

        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $this->validIds ??= $this->prefetchValidIds();

        if (! is_string($value) && ! is_int($value)) {
            $fail('validation.exists')->translate();

            return;
        }

        if (! isset($this->validIds[(string) $value])) {
            $fail('validation.exists')->translate();
        }
    }

    /** @return array<string, true> */
    private function prefetchValidIds(): array
    {
        $raw = Arr::get($this->data, $this->arrayKey, []);

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $submitted = collect($raw)
            ->filter(fn (mixed $v): bool => is_string($v) || is_int($v))
            ->map(fn (mixed $v): string => (string) $v)
            ->unique()
            ->values()
            ->all();

        if ($submitted === []) {
            return [];
        }

        $found = DB::table($this->table)
            ->whereIn($this->column, $submitted)
            ->where('team_id', $this->teamId)
            ->pluck($this->column)
            ->all();

        /** @var array<int, string> $foundAsStrings */
        $foundAsStrings = array_map(static fn (mixed $id): string => (string) $id, $found);

        return array_fill_keys($foundAsStrings, true);
    }
}
