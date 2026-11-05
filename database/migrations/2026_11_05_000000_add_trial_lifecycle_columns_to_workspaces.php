<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->timestamp('trial_extended_at')->nullable();
            $table->timestamp('trial_reminded_for')->nullable();
        });

        DB::table('workspaces')
            ->whereBetween('trial_ends_at', [now(), now()->addDays(3)->endOfDay()])
            ->update(['trial_reminded_for' => DB::raw('trial_ends_at')]);
    }
};
