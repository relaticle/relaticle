<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Mirrors App\Support\PlainText::fromHtml() in SQL so search sees what readers see.
    // The decoded entities are the ones production rich text actually holds.
    private const string VISIBLE_TEXT = <<<'SQL'
        regexp_replace(
            replace(replace(replace(replace(replace(replace(replace(replace(replace(
                regexp_replace(
                    regexp_replace(text_value, '<\s*br\s*/?\s*>|<\s*/\s*(p|div|li|tr|h[1-6]|blockquote)\s*>', ' ', 'gi'),
                    '<[^>]*>', '', 'g'
                ),
                U&'\00A0', ' '), '&nbsp;', ' '), '&#39;', ''''), '&#039;', ''''), '&#x27;', ''''),
                '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
            '\s+', ' ', 'g'
        )
        SQL;

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table(config('custom-fields.database.table_names.custom_field_values'), function (Blueprint $table): void {
            $table->text('visible_text')->nullable()->storedAs(self::VISIBLE_TEXT);
        });
    }
};
