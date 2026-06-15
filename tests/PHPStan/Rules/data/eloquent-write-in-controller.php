<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;

Company::create(['name' => 'x']);

$record = new Company;

$record->update(['name' => 'y']);

$record->delete();

$record->save();

Company::query()->where('name', 'x')->delete();
