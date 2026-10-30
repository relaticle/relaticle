<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Http\Controllers;

use Illuminate\View\View;
use Relaticle\Cloud\Marketing\Support\CompetitorFacts;

final readonly class AlternativesController
{
    public function show(string $competitor): View
    {
        /** @var array<int, string> $declared */
        $declared = config('comparisons.alternatives', []);

        abort_unless(in_array($competitor, $declared, true), 404);

        $facts = CompetitorFacts::all();

        abort_unless(isset($facts[$competitor]), 404);

        return view($competitor === 'attio' ? 'cloud::marketing.alternatives.attio' : 'cloud::marketing.alternatives.show', [
            'relaticle' => $facts['relaticle'],
            'competitor' => $facts[$competitor],
            'competitorSlug' => $competitor,
        ]);
    }
}
