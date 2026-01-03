<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

// Clean up orphaned import sessions hourly
// Deletes temp files from abandoned imports (user left without completing)
Schedule::command('import:cleanup')->everyFifteenMinutes();
