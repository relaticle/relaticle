<?php

declare(strict_types=1);

use App\Actions\CustomFields\ConvertSeededStatusFields;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Task status and Opportunity stage were seeded as select fields, so their options carry
 * no category and every terminal read falls back to a warning. Categories live on the
 * status field type, which stores single choices exactly as select does, so both move
 * across without touching an option id or a stored value.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (resolve(ConvertSeededStatusFields::class)->execute() as $tenantId => $summary) {
            Log::info('Converted the seeded status fields of a workspace.', [
                'tenant_id' => $tenantId,
                ...$summary,
            ]);
        }
    }
};
