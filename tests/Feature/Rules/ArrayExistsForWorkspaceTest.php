<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\ArrayExistsForWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

mutates(ArrayExistsForWorkspace::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
});

it('passes when all array values exist in the workspace-scoped table', function (): void {
    $companies = Company::factory()->count(3)->recycle([$this->user, $this->workspace])->create();

    $validator = Validator::make(
        ['company_ids' => $companies->pluck('id')->all()],
        ['company_ids.*' => [new ArrayExistsForWorkspace('companies', 'company_ids', $this->workspace->id)]],
    );

    expect($validator->passes())->toBeTrue();
});

it('fails the specific index when one value belongs to another workspace', function (): void {
    $valid = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $otherWorkspace = Workspace::factory()->create();
    $invalid = Company::factory()->for($otherWorkspace)->create();

    $validator = Validator::make(
        ['company_ids' => [$valid->id, $invalid->id, $valid->id]],
        ['company_ids.*' => [new ArrayExistsForWorkspace('companies', 'company_ids', $this->workspace->id)]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('company_ids.1'))->toBeTrue()
        ->and($validator->errors()->has('company_ids.0'))->toBeFalse()
        ->and($validator->errors()->has('company_ids.2'))->toBeFalse();
});

it('prefetches valid ids in a single query regardless of array size', function (): void {
    $companies = Company::factory()->count(8)->recycle([$this->user, $this->workspace])->create();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $validator = Validator::make(
        ['company_ids' => $companies->pluck('id')->all()],
        ['company_ids.*' => [new ArrayExistsForWorkspace('companies', 'company_ids', $this->workspace->id)]],
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
        ['company_ids.*' => [new ArrayExistsForWorkspace('companies', 'company_ids', $this->workspace->id)]],
    );

    expect($validator->passes())->toBeTrue()
        ->and(DB::getQueryLog())->toBeEmpty();
});

it('does not leak ids from other workspaces into the valid set', function (): void {
    $otherWorkspace = Workspace::factory()->create();
    $otherWorkspaceCompany = Company::factory()->for($otherWorkspace)->create();

    $validator = Validator::make(
        ['company_ids' => [$otherWorkspaceCompany->id]],
        ['company_ids.*' => [new ArrayExistsForWorkspace('companies', 'company_ids', $this->workspace->id)]],
    );

    expect($validator->fails())->toBeTrue();
});

it('rebuilds the prefetched id set when setData is called again', function (): void {
    $first = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $second = Company::factory()->recycle([$this->user, $this->workspace])->create();

    $rule = new ArrayExistsForWorkspace('companies', 'company_ids', $this->workspace->id);

    DB::enableQueryLog();
    DB::flushQueryLog();

    Validator::make(['company_ids' => [$first->id]], ['company_ids.*' => [$rule]])->passes();
    Validator::make(['company_ids' => [$second->id]], ['company_ids.*' => [$rule]])->passes();

    $lookups = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'from "companies"'))
        ->count();

    expect($lookups)->toBe(2);
});
