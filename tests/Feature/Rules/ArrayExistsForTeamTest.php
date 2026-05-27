<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use App\Rules\ArrayExistsForTeam;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

mutates(ArrayExistsForTeam::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

it('passes when all array values exist in the team-scoped table', function (): void {
    $companies = Company::factory()->count(3)->recycle([$this->user, $this->team])->create();

    $validator = Validator::make(
        ['company_ids' => $companies->pluck('id')->all()],
        ['company_ids.*' => [new ArrayExistsForTeam('companies', 'company_ids', $this->team->id)]],
    );

    expect($validator->passes())->toBeTrue();
});

it('fails the specific index when one value belongs to another team', function (): void {
    $valid = Company::factory()->recycle([$this->user, $this->team])->create();
    $otherTeam = Team::factory()->create();
    $invalid = Company::factory()->for($otherTeam)->create();

    $validator = Validator::make(
        ['company_ids' => [$valid->id, $invalid->id, $valid->id]],
        ['company_ids.*' => [new ArrayExistsForTeam('companies', 'company_ids', $this->team->id)]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('company_ids.1'))->toBeTrue()
        ->and($validator->errors()->has('company_ids.0'))->toBeFalse()
        ->and($validator->errors()->has('company_ids.2'))->toBeFalse();
});

it('prefetches valid ids in a single query regardless of array size', function (): void {
    $companies = Company::factory()->count(8)->recycle([$this->user, $this->team])->create();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $validator = Validator::make(
        ['company_ids' => $companies->pluck('id')->all()],
        ['company_ids.*' => [new ArrayExistsForTeam('companies', 'company_ids', $this->team->id)]],
    );
    $validator->passes();

    $lookups = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'from "companies"'))
        ->count();

    expect($lookups)->toBe(1);
});

it('returns empty-valid-set without querying when input array is empty', function (): void {
    DB::enableQueryLog();
    DB::flushQueryLog();

    $validator = Validator::make(
        ['company_ids' => []],
        ['company_ids.*' => [new ArrayExistsForTeam('companies', 'company_ids', $this->team->id)]],
    );

    expect($validator->passes())->toBeTrue()
        ->and(DB::getQueryLog())->toBeEmpty();
});

it('does not leak ids from other teams into the valid set', function (): void {
    $otherTeam = Team::factory()->create();
    $otherTeamCompany = Company::factory()->for($otherTeam)->create();

    $validator = Validator::make(
        ['company_ids' => [$otherTeamCompany->id]],
        ['company_ids.*' => [new ArrayExistsForTeam('companies', 'company_ids', $this->team->id)]],
    );

    expect($validator->fails())->toBeTrue();
});

it('rebuilds the prefetched id set when setData is called again', function (): void {
    $first = Company::factory()->recycle([$this->user, $this->team])->create();
    $second = Company::factory()->recycle([$this->user, $this->team])->create();

    $rule = new ArrayExistsForTeam('companies', 'company_ids', $this->team->id);

    DB::enableQueryLog();
    DB::flushQueryLog();

    Validator::make(['company_ids' => [$first->id]], ['company_ids.*' => [$rule]])->passes();
    Validator::make(['company_ids' => [$second->id]], ['company_ids.*' => [$rule]])->passes();

    $lookups = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'from "companies"'))
        ->count();

    expect($lookups)->toBe(2);
});
