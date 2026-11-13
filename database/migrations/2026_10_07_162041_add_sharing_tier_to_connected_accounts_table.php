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
        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->string('sharing_tier', 30)->nullable();
        });

        DB::table('users')
            ->whereNotNull('default_email_sharing_tier')
            ->eachById(function (object $user): void {
                DB::table('connected_accounts')
                    ->where('user_id', $user->id)
                    ->update(['sharing_tier' => $user->default_email_sharing_tier]);
            });
    }
};
