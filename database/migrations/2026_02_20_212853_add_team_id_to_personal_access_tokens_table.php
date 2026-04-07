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
        $this->ensureTeamsPrimaryKey();

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignUlid('team_id')->nullable()->after('tokenable_id')->index()->constrained('teams')->cascadeOnDelete();
        });
    }

    /**
     * The ULID migration may leave teams.id without a primary key on PostgreSQL.
     * A foreign key constraint requires a unique/primary constraint on the referenced column.
     */
    private function ensureTeamsPrimaryKey(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $hasPrimary = DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conrelid = 'teams'::regclass AND contype = 'p'"
        );

        if (! $hasPrimary) {
            Schema::table('teams', function (Blueprint $table): void {
                $table->primary('id');
            });
        }
    }
};
