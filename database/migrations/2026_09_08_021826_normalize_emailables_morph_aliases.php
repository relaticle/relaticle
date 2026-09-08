<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([CrmEntity::People, CrmEntity::Company, CrmEntity::Opportunity] as $entity) {
            DB::table('emailables')
                ->where('emailable_type', $entity->model())
                ->update(['emailable_type' => $entity->value]);
        }
    }
};
