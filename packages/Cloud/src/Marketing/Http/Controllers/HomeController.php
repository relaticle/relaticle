<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Http\Controllers;

use Illuminate\View\View;

final readonly class HomeController
{
    public function __invoke(): View
    {
        return view('cloud::marketing.home.index');
    }
}
