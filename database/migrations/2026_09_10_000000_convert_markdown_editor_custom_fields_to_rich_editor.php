<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The markdown editor is being removed from the field-type picker, and a type that is
 * no longer registered cannot be resolved, so every existing field has to move to the
 * rich editor first. Values move with them: the rich editor stores HTML, and markdown
 * left as-is would render as its own source on the record page.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('custom_fields')
            ->where('type', 'markdown-editor')
            ->orderBy('id')
            ->each(function (object $field): void {
                $isEncrypted = (bool) data_get(json_decode((string) $field->settings, true) ?? [], 'encrypted', false);

                DB::table('custom_field_values')
                    ->where('custom_field_id', $field->id)
                    ->whereNotNull('text_value')
                    ->where('text_value', '<>', '')
                    ->orderBy('id')
                    ->each(fn (object $value) => $this->convertValue($value, $isEncrypted));
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
