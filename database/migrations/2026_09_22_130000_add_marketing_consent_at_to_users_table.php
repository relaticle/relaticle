<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('marketing_consent_at')->nullable()->after('email_verified_at');
        });

        DB::table('users')
            ->whereNotNull('mailcoach_subscriber_uuid')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function (Collection $users): void {
                DB::table('users')
                    ->whereIn('id', $users->pluck('id'))
                    ->update(['marketing_consent_at' => DB::raw('coalesce(email_verified_at, created_at)')]);
            });
    }
};
