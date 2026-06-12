<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polar_orders', function (Blueprint $table): void {
            $table->id();
            $table->ulidMorphs('billable');
            $table->string('polar_id')->nullable();
            $table->string('status');
            $table->integer('amount');
            $table->integer('tax_amount');
            $table->integer('refunded_amount');
            $table->integer('refunded_tax_amount');
            $table->string('currency');
            $table->string('billing_reason');
            $table->string('customer_id');
            $table->string('product_id')->index();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('ordered_at');
            $table->timestamps();
        });
    }
};
