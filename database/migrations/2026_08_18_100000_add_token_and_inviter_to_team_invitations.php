<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_invitations', function (Blueprint $table): void {
            $table->foreignUlid('inviter_id')->nullable()->after('team_id')
                ->constrained('users')->nullOnDelete();
            $table->string('token', 64)->nullable()->unique()->after('role');
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->string('invite_link_default_role')->default(TeamRole::Editor->value);
        });
    }
};
