<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Store\ImportStore;
use Tests\Helpers\ImportExecutionFixture;

mutates(ExecuteImportJob::class);

beforeEach(function (): void {
    Event::fake()->except([TeamCreated::class]);

    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;

    Filament::setTenant($this->team);
});

afterEach(function (): void {
    if (isset($this->import)) {
        ImportStore::load($this->import->id)?->destroy();
        $this->import->delete();
    }
});

it('creates and links a company from imported work emails', function (): void {
    $this->team->update(['auto_create_companies' => true]);

    ImportExecutionFixture::readyStore($this, ['Name', 'Email'], [
        ImportExecutionFixture::row(2, ['Name' => 'Jane', 'Email' => 'jane@import-acme.com'], [
            'match_action' => RowMatchAction::Create->value,
        ]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Email', target: 'custom_fields_emails'),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::query()->where('team_id', $this->team->id)->where('name', 'Jane')->firstOrFail();

    expect($person->company_id)->not->toBeNull();
    expect(Company::query()->where('team_id', $this->team->id)->where('name', 'Import-acme')->exists())->toBeTrue();
});
