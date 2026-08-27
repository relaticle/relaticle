<?php

declare(strict_types=1);

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

// --- Missing Custom Field Type Tests ---

it('imports phone custom field with comma-separated numbers as array', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'phones', 'phone');

    ImportExecutionFixture::readyStore($this, ['Name', 'Phones'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Phones' => '+1-555-0101, +44-20-7946-0958'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Phones', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull();

    $jsonValue = collect($cfv->json_value)->all();
    expect($jsonValue)->toBeArray()
        ->toContain('+1-555-0101')
        ->toContain('+44-20-7946-0958');
});

it('imports link custom field with URL value', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'website', 'link');

    ImportExecutionFixture::readyStore($this, ['Name', 'Website'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Website' => 'https://example.com'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Website', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull();

    $jsonValue = collect($cfv->json_value)->all();
    expect($jsonValue)->toContain('https://example.com');
});

it('imports toggle custom field with truthy values', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'is_active', 'toggle');

    ImportExecutionFixture::readyStore($this, ['Name', 'Active'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Active' => 'yes'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Active', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->boolean_value)->toBeTrue();
});

it('imports toggle custom field with falsy values', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'opted_out', 'toggle');

    ImportExecutionFixture::readyStore($this, ['Name', 'OptedOut'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'OptedOut' => '0'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'OptedOut', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->boolean_value)->toBeFalse();
});

it('imports textarea custom field value', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'bio', 'textarea');

    ImportExecutionFixture::readyStore($this, ['Name', 'Bio'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Bio' => 'A long biography text that spans multiple lines conceptually.'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Bio', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->text_value)->toBe('A long biography text that spans multiple lines conceptually.');
});

it('imports rich-editor custom field value as text', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'detailed_notes', 'rich-editor');

    ImportExecutionFixture::readyStore($this, ['Name', 'Notes'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Notes' => '<p>Bold statement</p>'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Notes', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->text_value)->toBe('<p>Bold statement</p>');
});

it('imports markdown-editor custom field value as text', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'readme', 'markdown-editor');

    ImportExecutionFixture::readyStore($this, ['Name', 'Readme'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Readme' => '# Heading\n\nSome **bold** text'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Readme', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->text_value)->toBe('# Heading\n\nSome **bold** text');
});

it('imports checkbox-list custom field with option names resolved to IDs', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'interests', 'checkbox-list', 'people', ['Sports', 'Music', 'Tech']);
    $sportsOption = $cf->options->firstWhere('name', 'Sports');
    $techOption = $cf->options->firstWhere('name', 'Tech');

    ImportExecutionFixture::readyStore($this, ['Name', 'Interests'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Interests' => 'Sports, Tech'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Interests', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull();

    $jsonValue = collect($cfv->json_value)->all();
    expect($jsonValue)->toBeArray()
        ->toContain((string) $sportsOption->id)
        ->toContain((string) $techOption->id);
});

it('imports radio custom field with option name resolved to ID', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'size', 'radio', 'people', ['Small', 'Medium', 'Large']);
    $mediumOption = $cf->options->firstWhere('name', 'Medium');

    ImportExecutionFixture::readyStore($this, ['Name', 'Size'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Size' => 'Medium'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Size', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->string_value)->toBe((string) $mediumOption->id);
});

it('imports toggle-buttons custom field with option name resolved to ID', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'urgency', 'toggle-buttons', 'people', ['Low', 'Normal', 'Urgent']);
    $urgentOption = $cf->options->firstWhere('name', 'Urgent');

    ImportExecutionFixture::readyStore($this, ['Name', 'Urgency'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Urgency' => 'Urgent'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Urgency', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->string_value)->toBe((string) $urgentOption->id);
});

it('imports color-picker custom field value as text', function (): void {
    $cf = ImportExecutionFixture::customField($this, 'brand_color', 'color-picker');

    ImportExecutionFixture::readyStore($this, ['Name', 'Color'], [
        ImportExecutionFixture::row(2, ['Name' => 'John', 'Color' => '#ff5733'], ['match_action' => RowMatchAction::Create->value]),
    ], [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toField(source: 'Color', target: "custom_fields_{$cf->code}"),
    ]);

    ImportExecutionFixture::run($this);

    $person = People::where('team_id', $this->team->id)->where('name', 'John')->first();
    $cfv = ImportExecutionFixture::customFieldValue($this, (string) $person->id, (string) $cf->id);
    expect($cfv)->not->toBeNull()
        ->and($cfv->text_value)->toBe('#ff5733');
});
