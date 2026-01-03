<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Get all phone_number custom field IDs that are currently text type
        /** @var \Illuminate\Support\Collection<int, int|string> */
        $phoneFieldIds = DB::table('custom_fields')
            ->where('code', 'phone_number')
            ->where('type', 'text')
            ->pluck('id');

        if ($phoneFieldIds->isEmpty()) {
            return;
        }

        // Update the field type from text to phone
        DB::table('custom_fields')
            ->whereIn('id', $phoneFieldIds)
            ->update(['type' => 'phone']);

        // Migrate values from text_value to json_value (as JSON array)
        // Phone type uses multiChoice schema which stores values in json_value
        DB::table('custom_field_values')
            ->whereIn('custom_field_id', $phoneFieldIds)
            ->whereNotNull('text_value')
            ->whereNull('json_value')
            ->orderBy('id')
            ->each(function (object $row): void {
                DB::table('custom_field_values')
                    ->where('id', $row->id)
                    ->update([
                        'json_value' => json_encode([$row->text_value]),
                        'text_value' => null,
                    ]);
            });
    }
};
