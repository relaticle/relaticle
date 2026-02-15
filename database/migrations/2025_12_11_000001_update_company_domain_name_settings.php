<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * - Rename 'domain_name' to 'domains'
     * - Rename 'Domain Name' to 'Domains'
     * - Set allow_multiple, max_values, unique_per_entity_type settings
     *
     * Note: Value migration (string_value → json_value) is handled in
     * 2025_12_20_000000_migrate_to_ulid.php::phaseA9_migrateDomainFieldValues()
     */
    public function up(): void
    {
        // Get all company domain_name custom fields that need updating
        $fields = DB::table('custom_fields')
            ->where('code', 'domain_name')
            ->where('entity_type', 'company')
            ->get();

        foreach ($fields as $field) {
            $settings = json_decode($field->settings ?? '{}', true);

            // Set the new settings values
            $settings['allow_multiple'] = true;
            $settings['max_values'] = 5;
            $settings['unique_per_entity_type'] = true;

            // Rename field code and name, update settings
            DB::table('custom_fields')
                ->where('id', $field->id)
                ->update([
                    'code' => 'domains',
                    'name' => 'Domains',
                    'settings' => json_encode($settings),
                ]);
        }
    }
};
