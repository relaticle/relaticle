<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('slug')->nullable()->after('name');
        });

        $this->backfillSlugs();

        Schema::table('teams', function (Blueprint $table): void {
            $table->string('slug')->nullable(false)->unique()->change();
        });
    }

    private function backfillSlugs(): void
    {
        $teams = DB::table('teams')->orderBy('created_at')->get();
        $usedSlugs = [];

        foreach ($teams as $team) {
            $baseSlug = Str::slug($team->name);

            if ($baseSlug === '') {
                $baseSlug = Str::lower(Str::random(8));
            }

            $slug = $baseSlug;
            $counter = 2;

            while (in_array($slug, $usedSlugs, true)) {
                $slug = "{$baseSlug}-{$counter}";
                $counter++;
            }

            $usedSlugs[] = $slug;

            DB::table('teams')->where('id', $team->id)->update(['slug' => $slug]);
        }
    }
};
