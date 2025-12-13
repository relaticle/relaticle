<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->updateSqlite();
        } else {
            $this->updateStandard();
        }
    }

    private function updateStandard(): void
    {
        Schema::table('user_social_accounts', function (Blueprint $table): void {
            $table->dropColumn('user_id');
        });

        Schema::table('user_social_accounts', function (Blueprint $table): void {
            $table->ulid('user_id')->after('id');
        });
    }

    private function updateSqlite(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        // Get all columns except the ones we're changing
        $columns = DB::select('PRAGMA table_info(user_social_accounts)');
        $selectParts = [];

        foreach ($columns as $col) {
            if ($col->name !== 'user_id') {
                $selectParts[] = $col->name;
            }
        }

        $selectColumns = implode(', ', $selectParts);

        // Create new table, copy data, drop old, rename
        DB::statement("CREATE TABLE user_social_accounts_new AS SELECT {$selectColumns} FROM user_social_accounts");

        // Add the ULID column
        DB::statement('ALTER TABLE user_social_accounts_new ADD COLUMN user_id TEXT');

        DB::statement('DROP TABLE user_social_accounts');
        DB::statement('ALTER TABLE user_social_accounts_new RENAME TO user_social_accounts');

        DB::statement('PRAGMA foreign_keys = ON');
    }
};
