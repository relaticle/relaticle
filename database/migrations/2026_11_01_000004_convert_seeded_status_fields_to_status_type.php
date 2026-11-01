<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array<string, array<string, string>>> */
    private const array CATEGORIES = [
        'task' => [
            'status' => [
                'To do' => 'unstarted',
                'In progress' => 'started',
                'Done' => 'completed',
            ],
        ],
        'opportunity' => [
            'stage' => [
                'Prospecting' => 'unstarted',
                'Qualification' => 'started',
                'Needs Analysis' => 'started',
                'Value Proposition' => 'started',
                'Id. Decision Makers' => 'started',
                'Perception Analysis' => 'started',
                'Proposal/Price Quote' => 'started',
                'Negotiation/Review' => 'started',
                'Closed Won' => 'completed',
                'Closed Lost' => 'cancelled',
                'Onboarding' => 'unstarted',
                'Active' => 'started',
                'Renewal due' => 'started',
                'At risk' => 'started',
                'Renewed' => 'completed',
                'Churned' => 'cancelled',
                'Sourced' => 'unstarted',
                'Applied' => 'started',
                'Screen' => 'started',
                'Interview' => 'started',
                'Offer' => 'started',
                'Hired' => 'completed',
                'Declined' => 'cancelled',
                'Target' => 'unstarted',
                'Intro' => 'started',
                'First meeting' => 'started',
                'Partner meeting' => 'started',
                'Term sheet' => 'started',
                'Closed' => 'completed',
                'Passed' => 'cancelled',
            ],
        ],
    ];

    public function up(): void
    {
        foreach (self::CATEGORIES as $entityType => $fields) {
            foreach ($fields as $code => $categories) {
                $this->convert($entityType, $code, $categories);
            }
        }
    }

    /**
     * @param  array<string, string>  $categories
     */
    private function convert(string $entityType, string $code, array $categories): void
    {
        DB::table('custom_fields')
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->where('type', 'select')
            ->update(['type' => 'status', 'updated_at' => now()]);

        $statusFields = DB::table('custom_fields')
            ->select('id')
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->where('type', 'status');

        foreach (array_unique($categories) as $category) {
            DB::table('custom_field_options')
                ->whereIn('custom_field_id', $statusFields)
                ->whereIn('name', array_keys($categories, $category, true))
                ->whereRaw("(settings::jsonb ->> 'category') is null")
                ->update(['settings' => DB::raw(
                    "(case when jsonb_typeof(settings::jsonb) = 'object' then settings::jsonb else '{}'::jsonb end || jsonb_build_object('category', '{$category}'))::json",
                )]);
        }
    }
};
