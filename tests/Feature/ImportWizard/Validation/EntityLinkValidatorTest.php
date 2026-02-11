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

it('returns no validation errors for AlwaysCreate matcher', function (): void {
    Company::factory()->create([
        'name' => 'Acme Corp',
        'team_id' => $this->team->id,
    ]);

    $validator = new EntityLinkValidator((string) $this->team->id);
    $matcher = MatchableField::name();

    $error = $validator->validate($this->companyLink, $matcher, 'Nonexistent Company');

    expect($error)->toBeNull();
});

it('batch validates with no errors for AlwaysCreate matcher', function (): void {
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

it('returns validation error for UpdateOnly matcher when record not found', function (): void {
    $validator = new EntityLinkValidator((string) $this->team->id);
    $matcher = MatchableField::id();

    $error = $validator->validate($this->companyLink, $matcher, '99999');

    expect($error)->not->toBeNull()
        ->and($error)->toContain('99999');
});
