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

        Schema::create('meetings', function (Blueprint $table) use ($workspaceId): void {
            $table->ulid('id')->primary();
            $table->foreignUlid($workspaceId)->constrained(TenantMigration::table())->cascadeOnDelete();
            $table->foreignUlid('connected_account_id')->constrained('connected_accounts')->cascadeOnDelete();

            $table->string('provider_event_id');
            $table->string('provider_recurring_event_id')->nullable();
            $table->string('ical_uid')->nullable();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('all_day')->default(false);

            $table->string('organizer_email')->nullable();
            $table->string('organizer_name')->nullable();

            $table->string('status')->default('confirmed');
            $table->string('visibility')->default('default');
            $table->string('response_status')->nullable();
            $table->string('html_link')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['connected_account_id', 'provider_event_id']);
            $table->index([$workspaceId, 'starts_at']);
            $table->index('provider_recurring_event_id');
        });
    }
};
