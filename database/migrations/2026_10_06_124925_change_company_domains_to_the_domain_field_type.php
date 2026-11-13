<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('custom_fields')
            ->where('entity_type', 'company')
            ->where('code', 'domains')
            ->where('type', 'link')
            ->update([
                'type' => 'domain',
                'settings' => DB::raw(<<<'SQL'
                    case
                        when jsonb_typeof(settings::jsonb -> 'additional') = 'object'
                            then (settings::jsonb #- '{additional,link_variant}')::json
                        else settings
                    end
                    SQL),
            ]);
    }
};
