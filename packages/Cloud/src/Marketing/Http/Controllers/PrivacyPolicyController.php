<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\View\View;
use Relaticle\Cloud\Marketing\Support\LocalizedUrl;

final readonly class PrivacyPolicyController
{
    public function __invoke(): View
    {
        $policyFile = dirname(__DIR__, 4).'/resources/markdown/policy.md';

        return view('cloud::marketing.policy', [
            'policy' => LocalizedUrl::localizeLinks(Str::markdown(file_get_contents($policyFile) ?: '')),
        ]);
    }
}
