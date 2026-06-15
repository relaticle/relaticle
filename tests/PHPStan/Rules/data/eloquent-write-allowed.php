<?php

declare(strict_types=1);

namespace App\Actions\Fixture {
    use App\Models\Company;

    Company::create(['name' => 'allowed outside guarded namespaces']);

    (new Company)->save();
}

namespace App\Http\Controllers {
    use App\Models\Company;

    Company::query()->count();

    (new Company)->refresh();

    (new class
    {
        public function update(string $value): string
        {
            return $value;
        }
    })->update('write-sounding method on a non-Eloquent object');
}
