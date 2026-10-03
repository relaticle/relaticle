<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Models\Company;
use App\Models\CustomField;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LegacyCompanyDomains
{
    /**
     * @return array{own: Company, holder: Company, other: Company}
     */
    public static function seed(Workspace $workspace): array
    {
        $own = Company::factory()->for($workspace)->create(['name' => 'Own']);
        $holder = Company::factory()->for($workspace)->create(['name' => 'Holder']);
        $other = Company::factory()->for($workspace)->create(['name' => 'Other']);

        self::write($workspace, $own, ['https://acme.com']);
        self::write($workspace, $holder, ['acme.com']);
        self::write($workspace, $other, ['other.com']);

        return ['own' => $own, 'holder' => $holder, 'other' => $other];
    }

    /**
     * @param  list<string>  $domains
     */
    private static function write(Workspace $workspace, Company $company, array $domains): void
    {
        DB::table('custom_field_values')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $workspace->getKey(),
            'entity_type' => 'company',
            'entity_id' => $company->getKey(),
            'custom_field_id' => CustomField::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $workspace->getKey())
                ->where('entity_type', 'company')
                ->where('code', 'domains')
                ->firstOrFail()
                ->getKey(),
            'json_value' => json_encode($domains),
        ]);
    }
}
