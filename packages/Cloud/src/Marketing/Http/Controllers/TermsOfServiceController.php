<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\View\View;

final readonly class TermsOfServiceController
{
    public function __invoke(): View
    {
        $termsFile = dirname(__DIR__, 4).'/resources/markdown/terms.md';

        return view('cloud::marketing.terms', [
            'terms' => Str::markdown(file_get_contents($termsFile) ?: ''),
        ]);
    }
}
