<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Skip if already using ULID (fresh install)
        if ($this->isAlreadyUlid('users', 'id')) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        // Companies: team_id, creator_id, account_owner_id -> ULIDs
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['team_id', 'creator_id', 'account_owner_id']);
        });
        Schema::table('companies', function (Blueprint $table): void {
            $table->renameColumn('team_ulid', 'team_id');
            $table->renameColumn('creator_ulid', 'creator_id');
            $table->renameColumn('account_owner_ulid', 'account_owner_id');
        });

        // People: team_id, creator_id, company_id -> ULIDs
        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn(['team_id', 'creator_id', 'company_id']);
        });
        Schema::table('people', function (Blueprint $table): void {
            $table->renameColumn('team_ulid', 'team_id');
            $table->renameColumn('creator_ulid', 'creator_id');
            $table->renameColumn('company_ulid', 'company_id');
        });

        // Opportunities: team_id, creator_id, company_id, contact_id -> ULIDs
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropColumn(['team_id', 'creator_id', 'company_id', 'contact_id']);
        });
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->renameColumn('team_ulid', 'team_id');
            $table->renameColumn('creator_ulid', 'creator_id');
            $table->renameColumn('company_ulid', 'company_id');
            $table->renameColumn('contact_ulid', 'contact_id');
        });

        // Tasks: team_id, creator_id -> ULIDs
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn(['team_id', 'creator_id']);
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->renameColumn('team_ulid', 'team_id');
            $table->renameColumn('creator_ulid', 'creator_id');
        });

        // Notes: team_id, creator_id -> ULIDs
        Schema::table('notes', function (Blueprint $table): void {
            $table->dropColumn(['team_id', 'creator_id']);
        });
        Schema::table('notes', function (Blueprint $table): void {
            $table->renameColumn('team_ulid', 'team_id');
            $table->renameColumn('creator_ulid', 'creator_id');
        });

        // Users: current_team_id -> ULID
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('current_team_id');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('current_team_ulid', 'current_team_id');
        });

        // Teams: user_id -> ULID
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('user_id');
        });
        Schema::table('teams', function (Blueprint $table): void {
            $table->renameColumn('user_ulid', 'user_id');
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Check if a column is already using ULID (char/string type).
     */
    private function isAlreadyUlid(string $table, string $column): bool
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return false;
        }

        $columnType = Schema::getColumnType($table, $column);

        return in_array($columnType, ['string', 'char', 'varchar', 'bpchar']);
    }
};
