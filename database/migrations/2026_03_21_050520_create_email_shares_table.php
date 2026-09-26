<?php

declare(strict_types=1);

use App\Support\Migrations\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $workspaceId = TenantMigration::foreignKeyColumn();

        Schema::create('email_shares', function (Blueprint $table) use ($workspaceId): void {
            $table->ulid('id')->primary();
            $table->foreignUlid($workspaceId)->constrained(TenantMigration::table())->cascadeOnDelete();
            $table->foreignUlid('email_id')->constrained('emails')->cascadeOnDelete();
            $table->foreignUlid('shared_by')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('shared_with')->constrained('users')->cascadeOnDelete();
            $table->string('tier', 30);                      // metadata_only | subject | full
            $table->timestamps();

            $table->index([$workspaceId, 'shared_with']);
            $table->index(['shared_with', 'email_id']);
            $table->unique(['email_id', 'shared_with'], 'email_shares_email_user_unique');
        });
    }
};
