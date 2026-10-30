<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\View\View;

final readonly class PrivacyPolicyController
{
    public function __invoke(): View
    {
        $policyFile = dirname(__DIR__, 4).'/resources/markdown/policy.md';

        return view('cloud::marketing.policy', [
            'policy' => Str::markdown(file_get_contents($policyFile) ?: ''),
        ]);
    }
}
