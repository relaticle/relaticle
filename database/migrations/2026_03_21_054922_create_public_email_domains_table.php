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

        Schema::create('public_email_domains', function (Blueprint $table) use ($workspaceId): void {
            $table->ulid('id')->primary();
            $table->foreignUlid($workspaceId)->constrained(TenantMigration::table())->cascadeOnDelete();
            $table->string('domain');
            $table->timestamps();

            $table->unique([$workspaceId, 'domain']);
        });
    }
};
