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
            ->eachById(function (object $field): void {
                $settings = json_decode($field->settings ?? '{}', true) ?: [];
                $settings['additional'] = [...($settings['additional'] ?? []), 'link_variant' => 'domain'];

                DB::table('custom_fields')->where('id', $field->id)->update(['settings' => json_encode($settings)]);
            });
    }
};
