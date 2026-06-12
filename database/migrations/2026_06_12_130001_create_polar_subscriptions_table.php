<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polar_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->ulidMorphs('billable');
            $table->string('type');
            $table->string('polar_id')->unique();
            $table->string('status');
            $table->string('product_id');
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }
};
