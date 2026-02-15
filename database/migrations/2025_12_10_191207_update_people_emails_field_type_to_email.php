<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * - Change type from 'tags-input' to 'email'
     * - Set allow_multiple, max_values, unique_per_entity_type settings
     *
     * Note: Value migration (string_value → json_value) is handled in
     * 2025_12_20_000000_migrate_to_ulid.php::phaseA8_migrateEmailFieldValues()
     */
    public function up(): void
    {
        // Get all people emails custom fields that need updating
        $fields = DB::table('custom_fields')
            ->where('code', 'emails')
            ->where('entity_type', 'people')
            ->where('type', 'tags-input')
            ->get();

        foreach ($fields as $field) {
            $settings = json_decode($field->settings ?? '{}', true);

            // Set the new settings values
            $settings['allow_multiple'] = true;
            $settings['max_values'] = 5;
            $settings['unique_per_entity_type'] = true;

            DB::table('custom_fields')
                ->where('id', $field->id)
                ->update([
                    'type' => 'email',
                    'settings' => json_encode($settings),
                ]);
        }
    }
};
