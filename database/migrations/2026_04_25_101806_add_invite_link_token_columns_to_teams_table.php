<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('invite_link_token', 40)->nullable()->unique()->after('slug');
            $table->timestamp('invite_link_token_expires_at')->nullable()->after('invite_link_token');
        });

        Team::query()
            ->whereNull('invite_link_token')
            ->lazyById()
            ->each(function (Team $team): void {
                $team->forceFill([
                    'invite_link_token' => Str::random(40),
                    'invite_link_token_expires_at' => now()->addDays(Team::INVITE_LINK_TTL_DAYS),
                ])->saveQuietly();
            });
    }
};
