<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\MatchableField;
use Relaticle\ImportWizard\Enums\EntityLinkSource;
use Relaticle\ImportWizard\Support\EntityLinkValidator;

mutates(EntityLinkValidator::class);

beforeEach(function (): void {
    Event::fake()->except([TeamCreated::class]);

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->personalTeam();

    Filament::setTenant($this->team);

    $this->companyLink = new EntityLink(
        key: 'company',
        source: EntityLinkSource::Relationship,
        targetEntity: 'company',
        targetModelClass: Company::class,
    );
});

it('returns no validation errors for Create matcher', function (): void {
    Company::factory()->create([
        'name' => 'Acme Corp',
        'team_id' => $this->team->id,
    ]);

    $validator = new EntityLinkValidator((string) $this->team->id);
    $matcher = MatchableField::name();

    $error = $validator->validate($this->companyLink, $matcher, 'Nonexistent Company');

    expect($error)->toBeNull();
});

it('batch validates with no errors for Create matcher', function (): void {
    Company::factory()->create([
        'name' => 'Acme Corp',
        'team_id' => $this->team->id,
    ]);

    $validator = new EntityLinkValidator((string) $this->team->id);
    $matcher = MatchableField::name();

    $results = $validator->batchValidate($this->companyLink, $matcher, ['Nonexistent', 'Acme Corp', 'Another']);

    expect($results)->toBe([
        'Nonexistent' => null,
        'Acme Corp' => null,
        'Another' => null,
    ]);
});

it('returns validation error for MatchOnly matcher when record not found', function (): void {
    $validator = new EntityLinkValidator((string) $this->team->id);
    $matcher = MatchableField::id();

    $error = $validator->validate($this->companyLink, $matcher, '99999');

    expect($error)->not->toBeNull()
        ->and($error)->toContain('99999');
});

it('returns null for empty value', function (): void {
    $validator = new EntityLinkValidator((string) $this->team->id);
    $matcher = MatchableField::id();

    expect($validator->validate($this->companyLink, $matcher, ''))->toBeNull();
    expect($validator->validate($this->companyLink, $matcher, null))->toBeNull();
    expect($validator->validate($this->companyLink, $matcher, '  '))->toBeNull();
});

it('returns null for existing record found by id', function (): void {
    $company = Company::factory()->create(['team_id' => $this->team->id]);

    $validator = new EntityLinkValidator((string) $this->team->id);
    $matcher = MatchableField::id();

    expect($validator->validate($this->companyLink, $matcher, (string) $company->id))->toBeNull();
});

it('batch validates with mixed found and not found records', function (): void {
    $company = Company::factory()->create(['team_id' => $this->team->id]);

    $validator = new EntityLinkValidator((string) $this->team->id);
    $matcher = MatchableField::id();

    $results = $validator->batchValidate(
        $this->companyLink,
        $matcher,
        [(string) $company->id, '99999'],
    );

    expect($results[(string) $company->id])->toBeNull()
        ->and($results['99999'])->not->toBeNull()
        ->and($results['99999'])->toContain('99999');
});

it('batch validates with empty values returns null for empty', function (): void {
    $validator = new EntityLinkValidator((string) $this->team->id);
    $matcher = MatchableField::id();

    $results = $validator->batchValidate($this->companyLink, $matcher, ['', '  ']);

    expect($results)->toBe(['' => null, '  ' => null]);
});

it('batch validates returns early with empty array after trimming', function (): void {
    $validator = new EntityLinkValidator((string) $this->team->id);
    $matcher = MatchableField::id();

    $results = $validator->batchValidate($this->companyLink, $matcher, ['']);

    expect($results)->toBe(['' => null]);
});

it('getResolvedId returns null for empty value', function (): void {
    $validator = new EntityLinkValidator((string) $this->team->id);
    $matcher = MatchableField::id();

    expect($validator->getResolvedId($this->companyLink, $matcher, ''))->toBeNull();
    expect($validator->getResolvedId($this->companyLink, $matcher, null))->toBeNull();
});

it('getResolvedId resolves existing record', function (): void {
    $company = Company::factory()->create(['team_id' => $this->team->id]);

    $validator = new EntityLinkValidator((string) $this->team->id);
    $matcher = MatchableField::id();

    $id = $validator->getResolvedId($this->companyLink, $matcher, (string) $company->id);

    expect($id)->toBe($company->id);
});

it('builds error message with entity link label and matcher info', function (): void {
    $link = new EntityLink(
        key: 'company',
        source: EntityLinkSource::Relationship,
        targetEntity: 'company',
        targetModelClass: Company::class,
        label: 'Company',
    );

    $validator = new EntityLinkValidator((string) $this->team->id);
    $matcher = MatchableField::id();

    $error = $validator->validate($link, $matcher, '99999');

    expect($error)
        ->toContain('Company')
        ->toContain('99999')
        ->toContain('Record ID');
});

it('getResolvedId returns null for whitespace-only value', function (): void {
    $validator = new EntityLinkValidator((string) $this->team->id);
    $matcher = MatchableField::id();

    expect($validator->getResolvedId($this->companyLink, $matcher, '   '))->toBeNull();
});

it('getResolvedId returns null for non-existing record', function (): void {
    $validator = new EntityLinkValidator((string) $this->team->id);
    $matcher = MatchableField::id();

    expect($validator->getResolvedId($this->companyLink, $matcher, '99999'))->toBeNull();
});

it('validateFromColumn returns null for field mapping column', function (): void {
    $column = \Relaticle\ImportWizard\Data\ColumnData::toField(source: 'Name', target: 'name');
    $importer = Mockery::mock(\Relaticle\ImportWizard\Importers\BaseImporter::class);

    $validator = new EntityLinkValidator((string) $this->team->id);

    expect($validator->validateFromColumn($column, $importer, 'test'))->toBeNull();
});

it('validateFromColumn validates entity link column', function (): void {
    $column = \Relaticle\ImportWizard\Data\ColumnData::toEntityLink(
        source: 'Company',
        matcherKey: 'id',
        entityLinkKey: 'company',
    );
    $column->entityLinkField = new EntityLink(
        key: 'company',
        source: EntityLinkSource::Relationship,
        targetEntity: 'company',
        targetModelClass: Company::class,
        matchableFields: [MatchableField::id()],
    );

    $importer = Mockery::mock(\Relaticle\ImportWizard\Importers\BaseImporter::class);

    $validator = new EntityLinkValidator((string) $this->team->id);

    $error = $validator->validateFromColumn($column, $importer, '99999');

    expect($error)->not->toBeNull()
        ->and($error)->toContain('99999');
});

it('batchValidateFromColumn returns null for field mapping column', function (): void {
    $column = \Relaticle\ImportWizard\Data\ColumnData::toField(source: 'Name', target: 'name');
    $importer = Mockery::mock(\Relaticle\ImportWizard\Importers\BaseImporter::class);

    $validator = new EntityLinkValidator((string) $this->team->id);

    $results = $validator->batchValidateFromColumn($column, $importer, ['test1', 'test2']);

    expect($results)->toBe(['test1' => null, 'test2' => null]);
});

it('batchValidateFromColumn validates entity link column', function (): void {
    $company = Company::factory()->create(['team_id' => $this->team->id]);

    $column = \Relaticle\ImportWizard\Data\ColumnData::toEntityLink(
        source: 'Company',
        matcherKey: 'id',
        entityLinkKey: 'company',
    );
    $column->entityLinkField = new EntityLink(
        key: 'company',
        source: EntityLinkSource::Relationship,
        targetEntity: 'company',
        targetModelClass: Company::class,
        matchableFields: [MatchableField::id()],
    );

    $importer = Mockery::mock(\Relaticle\ImportWizard\Importers\BaseImporter::class);
    $validator = new EntityLinkValidator((string) $this->team->id);

    $results = $validator->batchValidateFromColumn($column, $importer, [(string) $company->id, '99999']);

    expect($results[(string) $company->id])->toBeNull()
        ->and($results['99999'])->not->toBeNull();
});
