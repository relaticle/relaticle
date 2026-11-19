<?php

declare(strict_types=1);

use App\Enums\CustomFields\CompanyField;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Models\Concerns\UsesCustomFields;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

mutates(Company::class);

test('company belongs to workspace', function () {
    $workspace = Workspace::factory()->create();
    $company = Company::factory()->create([
        'workspace_id' => $workspace->getKey(),
    ]);

    expect($company->workspace)->toBeInstanceOf(Workspace::class)
        ->and($company->workspace->getKey())->toBe($workspace->getKey());
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

    expect($company->people()->withoutGlobalScope(WorkspaceScope::class)->first())->toBeInstanceOf(People::class)
        ->and($company->people()->withoutGlobalScope(WorkspaceScope::class)->first()->getKey())->toBe($people->getKey());
});

test('company has many opportunities', function () {
    $company = Company::factory()->create();
    $opportunity = Opportunity::factory()->create([
        'company_id' => $company->getKey(),
    ]);

    expect($company->opportunities()->withoutGlobalScope(WorkspaceScope::class)->first())->toBeInstanceOf(Opportunity::class)
        ->and($company->opportunities()->withoutGlobalScope(WorkspaceScope::class)->first()->getKey())->toBe($opportunity->getKey());
});

test('company morph to many tasks', function () {
    $company = Company::factory()->create();
    $task = Task::factory()->create();

    $company->tasks()->attach($task);

    expect($company->tasks()->withoutGlobalScope(WorkspaceScope::class)->first())->toBeInstanceOf(Task::class)
        ->and($company->tasks()->withoutGlobalScope(WorkspaceScope::class)->first()->getKey())->toBe($task->getKey());
});

test('company morph to many notes', function () {
    $company = Company::factory()->create();
    $note = Note::factory()->create();

    $company->notes()->attach($note);

    expect($company->notes()->withoutGlobalScope(WorkspaceScope::class)->first())->toBeInstanceOf(Note::class)
        ->and($company->notes()->withoutGlobalScope(WorkspaceScope::class)->first()->getKey())->toBe($note->getKey());
});

test('company logo is null until one is uploaded', function () {
    $company = Company::factory()->create([
        'name' => 'Test Company',
    ]);

    expect($company->logo)->toBeNull();

    $company->addMediaFromString(onePixelPng())
        ->usingFileName('logo.png')
        ->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);

    expect($company->refresh()->logo)->toContain('logo.png');
});

test('company prefers a stored logo, then Brandfetch, then nothing', function () {
    config()->set('services.brandfetch.client_id', 'test-client');
    $company = Company::factory()->create(['custom_fields' => [CompanyField::DOMAINS->value => ['example.com']]]);

    expect($company->fresh()->logo)->toBe('https://cdn.brandfetch.io/domain/example.com/w/128/h/128/fallback/404/icon?c=test-client');

    $company->addMediaFromString(onePixelPng())
        ->usingFileName('logo.png')
        ->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);

    expect($company->fresh()->logo)->toContain('logo.png');

    config()->set('services.brandfetch.client_id', null);
    $company->clearMediaCollection(Company::LOGO_MEDIA_COLLECTION);

    expect($company->fresh()->logo)->toBeNull()
        ->and($company->fresh()->getFilamentAvatarUrl())->toBeNull();
});

test('company builds one clean Brandfetch url from messy domains', function (array $domains, ?string $expected) {
    config()->set('services.brandfetch.client_id', 'test-client');
    $company = Company::factory()->create(['custom_fields' => [CompanyField::DOMAINS->value => $domains]]);

    expect($company->fresh()->brandfetchUrl())->toBe($expected === null
        ? null
        : "https://cdn.brandfetch.io/domain/{$expected}/w/128/h/128/fallback/404/icon?c=test-client");
})->with([
    'padded and capitalised' => [[' Example.COM '], 'example.com'],
    'scheme, www and path' => [['https://www.example.com/path'], 'example.com'],
    'port and trailing dot' => [['example.com.:8443'], 'example.com'],
    'query string' => [['http://example.com?utm=1'], 'example.com'],
    'multi-value field' => [['https://example.com', 'other.org'], 'example.com'],
    'subdomain kept' => [['app.example.co.uk'], 'app.example.co.uk'],
    'first valid value of several' => [['not a domain', 'https://example.com'], 'example.com'],
    'unicode domain' => [['Bücher.de'], 'xn--bcher-kva.de'],
    'not a domain' => [['not a domain'], null],
    'no dot' => [['localhost'], null],
    'ip address' => [['192.168.0.1'], null],
    'empty' => [[''], null],
]);

test('company asks Brandfetch for nothing without a client id', function () {
    config()->set('services.brandfetch.client_id', null);
    $company = Company::factory()->create(['custom_fields' => [CompanyField::DOMAINS->value => ['example.com']]]);

    expect($company->fresh()->brandfetchUrl())->toBeNull();
});

test('company logos add no query per row on the companies and people lists', function (string $page) {
    config()->set('services.brandfetch.client_id', 'test-client');
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $createCompanies = fn (int $from, int $to) => collect(range($from, $to))->each(function (int $index) use ($user): void {
        $company = Company::factory()->recycle([$user, $user->currentWorkspace])->create([
            'custom_fields' => [CompanyField::DOMAINS->value => ["company-{$index}.com"]],
        ]);
        People::factory()->recycle([$user, $user->currentWorkspace])->create(['company_id' => $company->getKey()]);
    });
    $listQueryCount = function () use ($page): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = livewire($page)->html();

        expect($html)->toContain('cdn.brandfetch.io/domain/company-1.com/');

        return count(DB::getQueryLog());
    };

    $createCompanies(1, 1);
    $listQueryCount();
    $withOneRow = $listQueryCount();
    $createCompanies(2, 10);

    expect($listQueryCount())->toBe($withOneRow);
})->with([ListCompanies::class, ListPeople::class]);

test('company ignores a domain stored against another workspace field', function () {
    config()->set('services.brandfetch.client_id', 'test-client');
    $user = User::factory()->withWorkspace()->create();
    $otherWorkspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $foreignField = CustomField::query()->withoutGlobalScopes()
        ->where('tenant_id', $otherWorkspace->getKey())->where('entity_type', 'company')
        ->where('code', CompanyField::DOMAINS->value)->firstOrFail();
    $company = Company::factory()->for($user->currentWorkspace)->create();

    CustomFieldValue::withoutEvents(fn () => CustomFieldValue::query()->forceCreate([
        'tenant_id' => $otherWorkspace->getKey(),
        'entity_type' => 'company',
        'entity_id' => $company->getKey(),
        'custom_field_id' => $foreignField->getKey(),
        'json_value' => ['foreign.com'],
    ]));

    expect($company->fresh()->brandfetchUrl())->toBeNull();
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
