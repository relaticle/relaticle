<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('workspaces')
            ->whereIn('onboarding_step', ['source', 'email', 'sharing'])
            ->update(['onboarding_step' => 'use_case']);
    }
};
