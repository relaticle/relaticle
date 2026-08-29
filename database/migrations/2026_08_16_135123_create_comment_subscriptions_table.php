<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comment_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('team_id')->nullable()->index();
            $table->ulidMorphs('commentable');
            $table->ulidMorphs('commenter');
            $table->timestamp('created_at')->nullable();

            $table->unique(['commentable_type', 'commentable_id', 'commenter_type', 'commenter_id'], 'comment_subscriptions_unique');
        });
    }
};
