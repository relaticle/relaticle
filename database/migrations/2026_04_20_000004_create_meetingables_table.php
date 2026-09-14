<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetingables', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->ulidMorphs('meetingable');
            $table->string('link_source', 20)->default('auto');

            $table->timestamps();
            $table->index('meeting_id');
            $table->unique(['meeting_id', 'meetingable_type', 'meetingable_id'], 'meetingables_unique_link');
        });
    }
};
