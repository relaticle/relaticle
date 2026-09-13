<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class HistoricalSchema
{
    public static function createTeamUserTable(bool $withUniqueConstraint = false): void
    {
        Schema::create('team_user', function (Blueprint $table) use ($withUniqueConstraint): void {
            $table->id();
            $table->string('team_id', 26);
            $table->string('user_id', 26);
            $table->string('role')->nullable();
            $table->timestamps();

            if ($withUniqueConstraint) {
                $table->unique(['team_id', 'user_id'], 'team_user_team_id_user_id_unique');
            }
        });
    }

    public static function createTeamInvitationsTable(): void
    {
        Schema::create('team_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('team_id', 26);
            $table->string('email');
            $table->string('role')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'email']);
        });
    }
}
