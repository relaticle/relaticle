<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('team_id')->nullable()->index();
            $table->ulidMorphs('commentable');
            $table->ulidMorphs('commenter');
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('comments')
                ->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('pinned_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['commentable_type', 'commentable_id', 'parent_id']);
        });
    }
};
