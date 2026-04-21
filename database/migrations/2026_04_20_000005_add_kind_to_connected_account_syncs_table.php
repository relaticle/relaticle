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
        Schema::table('connected_account_syncs', function (Blueprint $table): void {
            $table->string('kind', 20)->default('email')->after('connected_account_id');
            $table->index(['connected_account_id', 'kind']);
        });

        DB::table('connected_account_syncs')->update(['kind' => 'email']);
    }
};
