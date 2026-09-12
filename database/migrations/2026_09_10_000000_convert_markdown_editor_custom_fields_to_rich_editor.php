<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('custom_fields')
            ->where('type', 'markdown-editor')
            ->eachById(function (object $field): void {
                $isEncrypted = (bool) data_get(json_decode((string) $field->settings, true) ?? [], 'encrypted', false);

                DB::table('custom_field_values')
                    ->where('custom_field_id', $field->id)
                    ->whereNotNull('text_value')
                    ->where('text_value', '<>', '')
                    ->eachById(fn (object $value) => $this->convertValue($value, $isEncrypted));
            });

        DB::table('custom_fields')
            ->where('type', 'markdown-editor')
            ->update(['type' => 'rich-editor']);
    }

    private function convertValue(object $value, bool $isEncrypted): void
    {
        try {
            $markdown = $isEncrypted ? Crypt::decryptString($value->text_value) : $value->text_value;
        } catch (Throwable $e) {
            // Leaving it as markdown source shows the user their own text back, which
            // they can reformat. Failing the deploy over one value would not.
            Log::warning('Skipped a markdown custom field value that could not be read.', [
                'custom_field_value_id' => $value->id,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $html = trim(Str::markdown($markdown));

        DB::table('custom_field_values')
            ->where('id', $value->id)
            ->update(['text_value' => $isEncrypted ? Crypt::encryptString($html) : $html]);
    }
};
