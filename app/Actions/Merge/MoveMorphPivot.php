<?php

declare(strict_types=1);

namespace App\Actions\Merge;

use App\Models\Company;
use App\Models\People;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class MoveMorphPivot
{
    public function execute(string $table, string $itemColumn, string $prefix, Company|People $merged, Company|People $original): void
    {
        $this->linkedTo($table, $prefix, $original)
            ->whereIn($itemColumn, $this->linkedTo($table, $prefix, $merged)->select($itemColumn))
            ->delete();

        $this->linkedTo($table, $prefix, $original)->update([$prefix.'_id' => $merged->getKey()]);
    }

    private function linkedTo(string $table, string $prefix, Company|People $record): Builder
    {
        return DB::table($table)->where($prefix.'_type', $record->getMorphClass())->where($prefix.'_id', $record->getKey());
    }
}
