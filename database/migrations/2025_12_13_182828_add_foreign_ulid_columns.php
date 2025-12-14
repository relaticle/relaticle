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

        // Add ULID foreign key columns to core entity tables
        Schema::table('companies', function (Blueprint $table): void {
            $table->foreignUlid('team_ulid')->after('team_id')->nullable();
            $table->foreignUlid('creator_ulid')->after('creator_id')->nullable();
            $table->foreignUlid('account_owner_ulid')->after('account_owner_id')->nullable();
        });

        Schema::table('people', function (Blueprint $table): void {
            $table->foreignUlid('team_ulid')->after('team_id')->nullable();
            $table->foreignUlid('creator_ulid')->after('creator_id')->nullable();
            $table->foreignUlid('company_ulid')->after('company_id')->nullable();
        });

        Schema::table('opportunities', function (Blueprint $table): void {
            $table->foreignUlid('team_ulid')->after('team_id')->nullable();
            $table->foreignUlid('creator_ulid')->after('creator_id')->nullable();
            $table->foreignUlid('company_ulid')->after('company_id')->nullable();
            $table->foreignUlid('contact_ulid')->after('contact_id')->nullable();
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignUlid('team_ulid')->after('team_id')->nullable();
            $table->foreignUlid('creator_ulid')->after('creator_id')->nullable();
        });

        Schema::table('notes', function (Blueprint $table): void {
            $table->foreignUlid('team_ulid')->after('team_id')->nullable();
            $table->foreignUlid('creator_ulid')->after('creator_id')->nullable();
        });

        // Users table
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUlid('current_team_ulid')->after('current_team_id')->nullable();
        });

        // Teams table
        Schema::table('teams', function (Blueprint $table): void {
            $table->foreignUlid('user_ulid')->after('user_id')->nullable();
        });
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
