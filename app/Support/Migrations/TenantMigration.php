<?php

declare(strict_types=1);

namespace App\Support\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final readonly class TenantMigration
{
    public static function usesWorkspacesTable(): bool
    {
        return Schema::hasTable('workspaces') && ! Schema::hasTable('teams');
    }

    public static function table(): string
    {
        return self::usesWorkspacesTable() ? 'workspaces' : 'teams';
    }

    public static function foreignKeyColumn(): string
    {
        return 'workspace_id';
    }

    public static function personalColumn(): string
    {
        return self::usesWorkspacesTable() ? 'personal_workspace' : 'personal_team';
    }

    public static function addForeignKey(Blueprint $table): void
    {
        $table->foreignUlid(self::foreignKeyColumn())
            ->constrained(self::table())
            ->cascadeOnDelete();
    }
}
