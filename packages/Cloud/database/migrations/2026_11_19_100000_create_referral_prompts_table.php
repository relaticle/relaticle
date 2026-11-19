<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_prompts', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('outcome', 16);
            $table->timestamp('ended_at');
        });
    }
};
