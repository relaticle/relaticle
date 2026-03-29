<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Relaticle\CustomFields\Models\Concerns\UsesCustomFields;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

mutates(Company::class);

test('company belongs to team', function () {
    $team = Team::factory()->create();
    $company = Company::factory()->create([
        'team_id' => $team->getKey(),
    ]);

    expect($company->team)->toBeInstanceOf(Team::class)
        ->and($company->team->getKey())->toBe($team->getKey());
});

test('company belongs to creator', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create([
        'creator_id' => $user->getKey(),
    ]);

    expect($company->creator)->toBeInstanceOf(User::class)
        ->and($company->creator->getKey())->toBe($user->getKey());
});

test('company belongs to account owner', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create([
        'account_owner_id' => $user->getKey(),
    ]);

    expect($company->accountOwner)->toBeInstanceOf(User::class)
        ->and($company->accountOwner->getKey())->toBe($user->getKey());
});

test('company has many people', function () {
    $company = Company::factory()->create();
    $people = People::factory()->create([
        'company_id' => $company->getKey(),
    ]);

    expect($company->people->first())->toBeInstanceOf(People::class)
        ->and($company->people->first()->getKey())->toBe($people->getKey());
});

test('company has many opportunities', function () {
    $company = Company::factory()->create();
    $opportunity = Opportunity::factory()->create([
        'company_id' => $company->getKey(),
    ]);

    expect($company->opportunities->first())->toBeInstanceOf(Opportunity::class)
        ->and($company->opportunities->first()->getKey())->toBe($opportunity->getKey());
});

test('company morph to many tasks', function () {
    $company = Company::factory()->create();
    $task = Task::factory()->create();

    $company->tasks()->attach($task);

    expect($company->tasks->first())->toBeInstanceOf(Task::class)
        ->and($company->tasks->first()->getKey())->toBe($task->getKey());
});

test('company morph to many notes', function () {
    $company = Company::factory()->create();
    $note = Note::factory()->create();

    $company->notes()->attach($note);

    expect($company->notes->first())->toBeInstanceOf(Note::class)
        ->and($company->notes->first()->getKey())->toBe($note->getKey());
});

test('company has logo attribute', function () {
    $company = Company::factory()->create([
        'name' => 'Test Company',
    ]);

    expect($company->logo)->not->toBeNull();
});

test('company uses media library', function () {
    $company = Company::factory()->create();

    expect(class_implements($company))->toContain(HasMedia::class)
        ->and(class_uses_recursive($company))->toContain(InteractsWithMedia::class);
});

test('company uses custom fields', function () {
    $company = Company::factory()->create();

    expect(class_implements($company))->toContain(HasCustomFields::class)
        ->and(class_uses_recursive($company))->toContain(UsesCustomFields::class);
});
