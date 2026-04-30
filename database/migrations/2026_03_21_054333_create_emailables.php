<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emailables', function (Blueprint $table): void {
            $table->id();                                    // bigInteger PK (matches noteables/taskables)
            $table->foreignUlid('email_id')->constrained('emails')->cascadeOnDelete();
            $table->ulidMorphs('emailable');                 // emailable_type, emailable_id (ULID)
            $table->string('link_source', 20)->default('auto'); // auto | manual

            $table->timestamps();
            $table->index('email_id');
        });
    }
};
