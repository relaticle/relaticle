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

        Schema::create('email_signatures', function (Blueprint $table) use ($workspaceId): void {
            $table->ulid('id')->primary();
            $table->teams();
            $table->foreignUlid('connected_account_id')
                ->constrained('connected_accounts')
                ->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->longText('content_html');
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index([$workspaceId, 'connected_account_id']);
        });
    }
};
