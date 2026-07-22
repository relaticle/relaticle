<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->timestamp('hosted_free_grandfathered_at')->nullable();
        });

        DB::table('teams')->update([
            'hosted_free_grandfathered_at' => now(),
        ]);
    }
};
