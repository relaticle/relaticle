<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_field_values', function (Blueprint $table): void {
            $table->index(['custom_field_id', 'float_value'], 'cfv_field_float_idx');
            $table->index(['custom_field_id', 'date_value'], 'cfv_field_date_idx');
            $table->index(['custom_field_id', 'datetime_value'], 'cfv_field_datetime_idx');
            $table->index(['custom_field_id', 'string_value'], 'cfv_field_string_idx');
            $table->index(['custom_field_id', 'integer_value'], 'cfv_field_integer_idx');
            $table->index(['custom_field_id', 'boolean_value'], 'cfv_field_boolean_idx');
        });
    }
};
