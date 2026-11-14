<?php

declare(strict_types=1);

namespace App\Actions\Merge;

use App\Models\Company;
use App\Models\People;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class MoveMorphPivot
{
    public function execute(string $table, string $itemColumn, string $prefix, Company|People $kept, Company|People $duplicate): void
    {
        $morph = $kept->getMorphClass();

        $this->linkedTo($table, $prefix, $morph, $duplicate)
            ->whereIn($itemColumn, $this->linkedTo($table, $prefix, $morph, $kept)->select($itemColumn))
            ->delete();

        $this->linkedTo($table, $prefix, $morph, $duplicate)->update([$prefix.'_id' => $kept->getKey()]);
    }

    private function linkedTo(string $table, string $prefix, string $morph, Company|People $record): Builder
    {
        return DB::table($table)->where($prefix.'_type', $morph)->where($prefix.'_id', $record->getKey());
    }
}
