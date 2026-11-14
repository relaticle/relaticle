<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const string INDEX = 'emailables_manual_pins_idx';

    public function up(): void
    {
        $this->dropInvalidIndex();

        DB::statement('create index concurrently if not exists '.self::INDEX." on emailables (emailable_type, emailable_id) where link_source = 'manual'");
    }

    // A failed concurrent build leaves an invalid index behind, and `if not exists` would skip it on the retry.
    private function dropInvalidIndex(): void
    {
        $isInvalid = DB::table('pg_index')
            ->join('pg_class', 'pg_class.oid', '=', 'pg_index.indexrelid')
            ->where('pg_class.relname', self::INDEX)
            ->whereRaw("pg_class.relnamespace = 'public'::regnamespace")
            ->where('pg_index.indisvalid', false)
            ->exists();

        if ($isInvalid) {
            DB::statement('drop index concurrently '.self::INDEX);
        }
    }
};
