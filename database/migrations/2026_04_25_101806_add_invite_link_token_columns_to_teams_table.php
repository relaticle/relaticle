<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const int INVITE_LINK_TTL_DAYS = 7;

    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('invite_link_token', 40)->nullable()->unique()->after('slug');
            $table->timestamp('invite_link_token_expires_at')->nullable()->after('invite_link_token');
        });

        $expiresAt = now()->addDays(self::INVITE_LINK_TTL_DAYS);

        DB::table('teams')
            ->whereNull('invite_link_token')
            ->orderBy('id')
            ->each(function (object $team) use ($expiresAt): void {
                DB::table('teams')->where('id', $team->id)->update([
                    'invite_link_token' => Str::random(40),
                    'invite_link_token_expires_at' => $expiresAt,
                ]);
            });
    }
};
