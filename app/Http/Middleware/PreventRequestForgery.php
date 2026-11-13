<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as BasePreventRequestForgery;

final class PreventRequestForgery extends BasePreventRequestForgery
{
    protected $addHttpCookie = false;
}
