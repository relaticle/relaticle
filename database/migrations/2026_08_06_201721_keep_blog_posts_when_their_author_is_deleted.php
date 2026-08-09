<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ink ships blog_posts.author_id as ON DELETE CASCADE. Users are hard-deleted —
     * App\Models\User has no SoftDeletes and app:purge-scheduled-deletions runs
     * daily — so an author closing their account silently destroyed the company's
     * marketing content, past the post's own soft deletes and with no way back.
     *
     * The post is the asset; the author is an attribution. Orphan it instead.
     * The Filament form still requires an author, so this only affects deletion.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE blog_posts ALTER COLUMN author_id DROP NOT NULL');

        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->dropForeign(['author_id']);
            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
