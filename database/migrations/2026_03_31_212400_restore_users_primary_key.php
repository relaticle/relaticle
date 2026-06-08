<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restore the PRIMARY KEY on users.id.
 *
 * The ULID conversion (2025_12_20_000000_migrate_to_ulid) drops every foreign
 * key (Phase B0) and is meant to recreate the primary keys (B1) and foreign
 * keys (B9) afterwards. On environments where that migration aborted partway,
 * users.id was left as a plain char(26) column with no primary key.
 *
 * PostgreSQL then rejects ANY new foreign key referencing users.id with:
 *   SQLSTATE[42830]: there is no unique constraint matching given keys for
 *   referenced table "users"
 *
 * which blocks the agent_conversations / ai_credit_transactions /
 * pending_actions migrations that immediately follow this one. This migration
 * restores the missing constraint so those foreign keys can be created.
 *
 * It is idempotent and a no-op on healthy databases (fresh installs already
 * have the primary key).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || $this->usersHasPrimaryKey()) {
            return;
        }

        match (DB::getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE users ADD PRIMARY KEY (id)'),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE `users` ADD PRIMARY KEY (`id`)'),
            default => null,
        };
    }

    private function usersHasPrimaryKey(): bool
    {
        return match (DB::getDriverName()) {
            'pgsql' => (bool) DB::selectOne(
                "SELECT 1 FROM pg_constraint WHERE conrelid = 'users'::regclass AND contype = 'p'"
            ),
            'mysql', 'mariadb' => (bool) DB::selectOne(
                'SELECT 1 FROM information_schema.table_constraints
                 WHERE table_schema = DATABASE() AND table_name = ? AND constraint_type = ?',
                ['users', 'PRIMARY KEY']
            ),
            // SQLite (tests) always defines the primary key inline at create time.
            default => true,
        };
    }
};
