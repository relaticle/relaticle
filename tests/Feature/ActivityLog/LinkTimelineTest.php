<?php

declare(strict_types=1);

use App\Listeners\CustomFields\LogLinkChangeListener;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Services\TenantContextService;
use Tests\Helpers\RecordFieldFixture;

mutates(LogLinkChangeListener::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;

    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->getKey());

    $this->field = RecordFieldFixture::record($this->team, 'people', 'company', 'vendors', RelationshipCardinality::ManyToMany);

    $this->person = People::factory()->for($this->team)->create(['name' => 'Alice Doe']);
    $this->company = Company::factory()->for($this->team)->create(['name' => 'Acme Robotics']);

    $this->entriesFor = function (object $record): array {
        return Activity::query()
            ->where('subject_type', $record->getMorphClass())
            ->where('subject_id', $record->getKey())
            ->where('event', 'custom_field_changes')
            ->get()
            ->map(fn (Activity $activity): array => $activity->properties['custom_field_changes'][0])
            ->all();
    };
});

it('writes one timeline entry on each record when a link opens', function (): void {
    $this->person->update(['custom_fields' => ['vendors' => [$this->company->getKey()]]]);

    $near = ($this->entriesFor)($this->person);
    $far = ($this->entriesFor)($this->company);

    expect($near)->toHaveCount(1)
        ->and($near[0]['label'])->toBe('Vendors')
        ->and($near[0]['new']['label'])->toBe('Acme Robotics')
        ->and($near[0]['old']['value'])->toBeNull()
        ->and($far)->toHaveCount(1)
        ->and($far[0]['label'])->toBe('Vendors (People)')
        ->and($far[0]['new']['label'])->toBe('Alice Doe');
});

it('writes one timeline entry on each record when a link closes', function (): void {
    $this->person->update(['custom_fields' => ['vendors' => [$this->company->getKey()]]]);
    Activity::withoutGlobalScopes()->delete();

    $this->person->update(['custom_fields' => ['vendors' => []]]);

    $near = ($this->entriesFor)($this->person);
    $far = ($this->entriesFor)($this->company);

    expect($near)->toHaveCount(1)
        ->and($near[0]['old']['label'])->toBe('Acme Robotics')
        ->and($near[0]['new']['value'])->toBeNull()
        ->and($far)->toHaveCount(1)
        ->and($far[0]['old']['label'])->toBe('Alice Doe');
});

it('credits the link to the user who wrote it', function (): void {
    $this->person->update(['custom_fields' => ['vendors' => [$this->company->getKey()]]]);

    $activity = Activity::query()->where('event', 'custom_field_changes')->firstOrFail();

    expect($activity->causer_id)->toBe($this->user->getKey())
        ->and($activity->team_id)->toBe($this->team->getKey());
});

it('names each side with its own field when both ends render one', function (): void {
    [$near, $far] = RecordFieldFixture::paired($this->team, 'people', 'company', 'employer', 'staff', RelationshipCardinality::ManyToOne);

    $this->person->update(['custom_fields' => [$near->code => [$this->company->getKey()]]]);

    $personEntries = ($this->entriesFor)($this->person);
    $companyEntries = ($this->entriesFor)($this->company);

    expect($personEntries)->toHaveCount(1)
        ->and($personEntries[0]['label'])->toBe('Employer')
        ->and($companyEntries)->toHaveCount(1)
        ->and($companyEntries[0]['label'])->toBe('Staff');
});

it('leaves no second entry from the value observer', function (): void {
    $this->person->update(['custom_fields' => ['vendors' => [$this->company->getKey()]]]);

    expect(Activity::query()->where('event', 'custom_field_changes')->count())->toBe(2);
});
