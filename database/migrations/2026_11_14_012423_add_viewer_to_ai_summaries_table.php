<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (Schema::getIndexes('ai_summaries') as $index) {
            if ($index['unique'] && $index['columns'] === ['summarizable_type', 'summarizable_id', 'workspace_id']) {
                Schema::table('ai_summaries', fn (Blueprint $table) => $table->dropUnique($index['name']));
            }
        }
        Schema::table('ai_summaries', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unique(['summarizable_type', 'summarizable_id', 'workspace_id', 'user_id'], 'ai_summaries_viewer_unique');
        });
    }
};
