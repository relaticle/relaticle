<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\CompetitorFacts;
use Illuminate\View\View;

final readonly class AlternativesController
{
    public function show(string $competitor): View
    {
        /** @var array<int, string> $declared */
        $declared = config('comparisons.alternatives', []);

        abort_unless(in_array($competitor, $declared, true), 404);

        $facts = CompetitorFacts::all();

        abort_unless(isset($facts[$competitor]), 404);

        return view('alternatives.show', [
            'relaticle' => $facts['relaticle'],
            'competitor' => $facts[$competitor],
            'competitorSlug' => $competitor,
        ]);
    }
}
