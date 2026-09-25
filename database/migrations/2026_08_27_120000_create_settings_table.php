<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // `?? 'settings'`, not a config() default: spatie ships that key explicitly
        // set to null, so the key EXISTS and config()'s default is never reached.
        // Rector's ApplyDefaultInsteadOfNullCoalesceRector rewrites this and the
        // migration then tries to `create table ""`.
        $table = config('settings.repositories.database.table') ?? 'settings';

        Schema::create($table, function (Blueprint $table): void {
            $table->id();

            $table->string('group');
            $table->string('name');
            $table->boolean('locked')->default(false);
            $table->json('payload');

            $table->timestamps();

            $table->unique(['group', 'name']);
        });
    }
};
