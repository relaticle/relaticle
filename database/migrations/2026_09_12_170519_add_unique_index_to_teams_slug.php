<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Production has no unique index on teams.slug; local and CI got one from the
     * 2026_02_11 migration. Panel URLs resolve a workspace by slug, so a duplicate
     * points two owners at one workspace.
     */
    public function up(): void
    {
        if (Schema::hasIndex('teams', ['slug'], 'unique')) {
            return;
        }

        Schema::table('teams', function (Blueprint $table): void {
            $table->unique('slug');
        });
    }
};
