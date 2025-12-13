<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add ULID column to each table (keeping old integer ID for now)
        Schema::table('users', function (Blueprint $table): void {
            $table->ulid('ulid')->after('id')->nullable()->unique();
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->ulid('ulid')->after('id')->nullable()->unique();
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->ulid('ulid')->after('id')->nullable()->unique();
        });

        Schema::table('people', function (Blueprint $table): void {
            $table->ulid('ulid')->after('id')->nullable()->unique();
        });

        Schema::table('opportunities', function (Blueprint $table): void {
            $table->ulid('ulid')->after('id')->nullable()->unique();
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->ulid('ulid')->after('id')->nullable()->unique();
        });

        Schema::table('notes', function (Blueprint $table): void {
            $table->ulid('ulid')->after('id')->nullable()->unique();
        });
    }
};
