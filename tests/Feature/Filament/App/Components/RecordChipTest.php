<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Filament\Components\Forms\RecordSelect;
use App\Filament\Components\Infolists\RecordChipEntry;
use App\Filament\Components\RecordChip;
use App\Filament\Components\Tables\RecordChipColumn;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\Company;
use App\Models\Note;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

mutates(
    RecordChip::class,
    RecordChipColumn::class,
    RecordChipEntry::class,
    RecordSelect::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

function peoplePicker(string $name): Select
{
    $schema = PeopleResource::form(Schema::make(app(ListPeople::class))->model(People::class));

    foreach ($schema->getComponents(withHidden: true) as $component) {
        foreach ($component->getChildComponentContainers() as $container) {
            foreach ($container->getComponents(withHidden: true) as $child) {
                if ($child instanceof Select && $child->getName() === $name) {
                    return $child;
                }
            }
        }
    }

    throw new RuntimeException("No `{$name}` picker on the people form.");
}

function mediaQueryCount(): int
{
    return collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], '"media"'))
        ->count();
}

it('renders the person icon in the people list name column', function (): void {
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Adam Clark']);

    livewire(ListPeople::class)
        ->assertSee('Adam Clark')
        ->assertSee(CrmEntity::People->iconPath(), escape: false);
});

it('renders the company entity icon in the people list company column', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->getKey()]);

    livewire(ListPeople::class)
        ->assertSee('Acme Corp')
        ->assertSee(CrmEntity::Company->iconPath(), escape: false);
});

it('renders the company entity icon in the companies list name column', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);

    livewire(ListCompanies::class)
        ->assertSee(CrmEntity::Company->iconPath(), escape: false);
});

it('renders the person icon for every related person in a multi-record column', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    $people = People::factory(2)->recycle([$this->user, $this->workspace])->create();
    $note->people()->attach($people);

    $html = livewire(ManageNotes::class)->html();

    expect(substr_count($html, CrmEntity::People->iconPath()))->toBeGreaterThanOrEqual(2);
});

it('renders the person icon on the view page', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->assertSee(CrmEntity::People->iconPath(), escape: false);
});

it('renders the company entity icon in a picker option label', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);

    $label = peoplePicker('company_id')->getOptionLabelFromRecord($company);

    expect($label)->toContain(CrmEntity::Company->iconPath())
        ->and($label)->toContain('Acme Corp');
});

it('escapes a record name in a picker option label', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create([
        'name' => '<script>alert(1)</script>',
    ]);

    $label = peoplePicker('company_id')->getOptionLabelFromRecord($company);

    expect($label)->toContain('&lt;script&gt;')
        ->and($label)->not->toContain('<script>');
});

it('loads company logos in one query when a picker preloads its options', function (): void {
    Company::factory(3)->recycle([$this->user, $this->workspace])->create();

    $picker = peoplePicker('company_id');

    DB::enableQueryLog();
    $options = $picker->getOptionsFromRelationship();

    expect($options)->toHaveCount(3)
        ->and(mediaQueryCount())->toBeLessThanOrEqual(1);
});

it('loads company logos in one query on the companies list', function (): void {
    Company::factory(3)->recycle([$this->user, $this->workspace])->create();

    DB::enableQueryLog();
    livewire(ListCompanies::class)->assertOk();

    expect(mediaQueryCount())->toBeLessThanOrEqual(1);
});

it('loads company logos in one query on the people list', function (): void {
    $companies = Company::factory(3)->recycle([$this->user, $this->workspace])->create();

    foreach ($companies as $company) {
        People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->getKey()]);
    }

    DB::enableQueryLog();
    livewire(ListPeople::class)->assertOk();

    expect(mediaQueryCount())->toBeLessThanOrEqual(1);
});

it('falls back to the shared entity icon for a company with no logo', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);

    $chip = RecordChip::forRecord($company);

    expect($chip->imageUrl)->toBeNull()
        ->and($chip->iconPath)->toBe(CrmEntity::Company->iconPath())
        ->and($chip->toHtml())->toContain('<svg')
        ->and($chip->toHtml())->not->toContain('<img');
});

it('gives two logoless companies the same icon, so colour only ever means a real logo', function (): void {
    $acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);
    $zeta = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Zeta Industries']);

    expect(RecordChip::forRecord($acme)->iconPath)->toBe(RecordChip::forRecord($zeta)->iconPath);
});

it('draws every person with the shared person icon, keeping initials for workspace members', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Adam Clark']);

    expect(RecordChip::forRecord($person)->imageUrl)->toBeNull()
        ->and(RecordChip::forRecord($person)->iconPath)->toBe(CrmEntity::People->iconPath())
        ->and(RecordChip::forRecord($this->user)->imageUrl)->toBe($this->user->getFilamentAvatarUrl());
});

it('carries the avatar in a global search result title', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);

    $results = CompanyResource::getGlobalSearchResults('Acme');

    expect($results)->toHaveCount(1)
        ->and($results->first()->title->toHtml())->toContain(CrmEntity::Company->iconPath())
        ->and($results->first()->title->toHtml())->toContain('Acme Corp');
});

it('squares a company chip and rounds a person chip', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();

    $companyChip = RecordChip::forRecord($company)->toHtml();
    $personChip = RecordChip::forRecord($person)->toHtml();

    expect($companyChip)->toMatch('/\brounded(?![-\w])/')
        ->and($companyChip)->not->toContain('rounded-full')
        ->and($personChip)->toContain('rounded-full');
});

it('keeps a chip avatar smaller than the themed filament avatar', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();

    $chip = RecordChip::forRecord($person)->toHtml();

    expect($chip)->toContain('size-5')
        ->and($chip)->not->toContain('fi-avatar');
});

it('renders no chip when the related record is missing', function (): void {
    People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => null]);

    livewire(ListPeople::class)->assertOk();

    expect(RecordChip::forRecords(null))->toBe([]);
});
