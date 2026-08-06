<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo', function (Blueprint $table): void {
            $table->id();

            // One SEO row per model. HasSEO::addSEO() is public and also fires on
            // `created`, and morphOne()->withDefault() silently returns the first
            // match — so a duplicate would let the panel edit one row while the
            // site renders another, with nothing to surface the split.
            $table->morphs('model');
            $table->unique(['model_type', 'model_id']);

            $table->longText('description')->nullable();
            $table->string('title')->nullable();
            $table->string('image')->nullable();
            $table->string('author')->nullable();
            $table->string('robots')->nullable();
            $table->string('canonical_url')->nullable();

            $table->timestamps();
        });
    }
};
