<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_reactions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('team_id')->nullable()->index();
            $table->foreignId('comment_id')
                ->constrained('comments')
                ->cascadeOnDelete();
            $table->ulidMorphs('commenter');
            $table->string('reaction');
            $table->timestamps();

            $table->unique(['comment_id', 'commenter_id', 'commenter_type', 'reaction'], 'comment_reactions_unique');
        });
    }
};
