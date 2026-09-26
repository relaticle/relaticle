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

        Schema::create('workspace_email_blocklists', function (Blueprint $table) use ($workspaceId): void {
            $table->ulid('id')->primary();
            $table->foreignUlid($workspaceId)->constrained(TenantMigration::table())->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('value');
            $table->string('enforcement_level', 20)->default('blocked');
            $table->foreignUlid('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index([$workspaceId, 'type', 'value']);
        });
    }
};
