<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Filament\Components\Forms\RecordSelect;
use App\Filament\Components\Infolists\RecordChipEntry;
use App\Filament\Components\RecordChip;
use App\Filament\Components\Tables\Filters\RecordSelectFilter;
use App\Filament\Components\Tables\RecordChipColumn;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
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
    RecordSelectFilter::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
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

function assigneesFilterField(ManageTasks $page): Select
{
    foreach ($page->getTableFiltersForm()->getComponents(withHidden: true) as $component) {
        foreach ($component->getChildComponentContainers() as $container) {
            foreach ($container->getComponents(withHidden: true) as $child) {
                if ($child instanceof Select && $child->hasRelationship() && $child->getRelationshipName() === 'assignees') {
                    return $child;
                }
            }
        }
    }

    throw new RuntimeException('No assignees filter field on the tasks table.');
}

/**
 * @param  array<string|int, string>  $options
 * @return array<int, string>
 */
function pickerLabelText(array $options): array
{
    return array_values(array_map(
        fn (string $label): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($label))),
        $options,
    ));
}

function mediaQueryCount(): int
{
    return collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], '"media"'))
        ->count();
}

it('renders the person avatar in the people list name column', function (): void {
    $person = People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Adam Clark']);

    livewire(ListPeople::class)
        ->assertSee('Adam Clark')
        ->assertSee($person->avatar, escape: false);
});

it('renders the company entity icon in the people list company column', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Acme Corp']);
    People::factory()->recycle([$this->user, $this->team])->create(['company_id' => $company->getKey()]);

    livewire(ListPeople::class)
        ->assertSee('Acme Corp')
        ->assertSee(CrmEntity::Company->iconPath(), escape: false);
});

it('renders the company entity icon in the companies list name column', function (): void {
    Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Acme Corp']);

    livewire(ListCompanies::class)
        ->assertSee(CrmEntity::Company->iconPath(), escape: false);
});

it('renders an avatar for every related person in a multi-record column', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->team])->create();
    $people = People::factory(2)->recycle([$this->user, $this->team])->create();
    $note->people()->attach($people);

    $rendered = livewire(ManageNotes::class);

    foreach ($people as $person) {
        $rendered->assertSee($person->avatar, escape: false);
    }
});

it('renders the person avatar on the view page', function (): void {
    $person = People::factory()->recycle([$this->user, $this->team])->create();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->assertSee($person->avatar, escape: false);
});

it('renders the company entity icon in a picker option label', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Acme Corp']);

    $label = peoplePicker('company_id')->getOptionLabelFromRecord($company);

    expect($label)->toContain(CrmEntity::Company->iconPath())
        ->and($label)->toContain('Acme Corp');
});

it('escapes a record name in a picker option label', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create([
        'name' => '<script>alert(1)</script>',
    ]);

    $label = peoplePicker('company_id')->getOptionLabelFromRecord($company);

    expect($label)->toContain('&lt;script&gt;')
        ->and($label)->not->toContain('<script>');
});

it('loads company logos in one query when a picker preloads its options', function (): void {
    Company::factory(3)->recycle([$this->user, $this->team])->create();

    $picker = peoplePicker('company_id');

    DB::enableQueryLog();
    $options = $picker->getOptionsFromRelationship();

    expect($options)->toHaveCount(3)
        ->and(mediaQueryCount())->toBeLessThanOrEqual(1);
});

it('loads company logos in one query on the companies list', function (): void {
    Company::factory(3)->recycle([$this->user, $this->team])->create();

    DB::enableQueryLog();
    livewire(ListCompanies::class)->assertOk();

    expect(mediaQueryCount())->toBeLessThanOrEqual(1);
});

it('loads company logos in one query on the people list', function (): void {
    $companies = Company::factory(3)->recycle([$this->user, $this->team])->create();

    foreach ($companies as $company) {
        People::factory()->recycle([$this->user, $this->team])->create(['company_id' => $company->getKey()]);
    }

    DB::enableQueryLog();
    livewire(ListPeople::class)->assertOk();

    expect(mediaQueryCount())->toBeLessThanOrEqual(1);
});

it('renders member chips in the assignees filter and lets the caller order win', function (): void {
    $mate = User::factory()->create(['name' => 'Aaron Ant']);
    $this->team->users()->attach($mate, ['role' => 'editor']);

    $page = livewire(ManageTasks::class)->instance();
    $field = assigneesFilterField($page);

    expect($field->isHtmlAllowed())->toBeTrue()
        ->and($field->getOptionLabelFromRecord($this->user))->toContain($this->user->getFilamentAvatarUrl());

    expect(pickerLabelText($field->getOptionsFromRelationship()))
        ->toBe([$this->user->name, 'Aaron Ant']);
});

it('falls back to the shared entity icon for a company with no logo', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Acme Corp']);

    $chip = RecordChip::forRecord($company);

    expect($chip->imageUrl)->toBeNull()
        ->and($chip->iconPath)->toBe(CrmEntity::Company->iconPath())
        ->and($chip->toHtml())->toContain('<svg')
        ->and($chip->toHtml())->not->toContain('<img');
});

it('gives two logoless companies the same icon, so colour only ever means a real logo', function (): void {
    $acme = Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Acme Corp']);
    $zeta = Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Zeta Industries']);

    expect(RecordChip::forRecord($acme)->iconPath)->toBe(RecordChip::forRecord($zeta)->iconPath);
});

it('still colours people by name so two of them stay distinguishable', function (): void {
    $adam = People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Adam Clark']);
    $zoe = People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Zoe Baker']);

    expect($adam->avatar)->not->toBe($zoe->avatar)
        ->and(RecordChip::forRecord($adam)->iconPath)->toBeNull()
        ->and(RecordChip::forRecord($adam)->imageUrl)->toBe($adam->avatar);
});

it('carries the avatar in a global search result title', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Acme Corp']);

    $results = CompanyResource::getGlobalSearchResults('Acme');

    expect($results)->toHaveCount(1)
        ->and($results->first()->title->toHtml())->toContain(CrmEntity::Company->iconPath())
        ->and($results->first()->title->toHtml())->toContain('Acme Corp');
});

it('squares a company chip and rounds a person chip', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create();
    $person = People::factory()->recycle([$this->user, $this->team])->create();

    $companyChip = RecordChip::forRecord($company)->toHtml();
    $personChip = RecordChip::forRecord($person)->toHtml();

    expect($companyChip)->toMatch('/\brounded(?![-\w])/')
        ->and($companyChip)->not->toContain('rounded-full')
        ->and($personChip)->toContain('rounded-full');
});

it('keeps a chip avatar smaller than the themed filament avatar', function (): void {
    $person = People::factory()->recycle([$this->user, $this->team])->create();

    $chip = RecordChip::forRecord($person)->toHtml();

    expect($chip)->toContain('size-5')
        ->and($chip)->not->toContain('fi-avatar');
});

it('renders no chip when the related record is missing', function (): void {
    People::factory()->recycle([$this->user, $this->team])->create(['company_id' => null]);

    livewire(ListPeople::class)->assertOk();

    expect(RecordChip::forRecords(null))->toBe([]);
});
