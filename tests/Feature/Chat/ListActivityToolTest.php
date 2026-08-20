<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Actions\Company\DeleteCompany;
use App\Actions\Company\UpdateCompany;
use App\Actions\CustomFields\CreateCustomField;
use App\Models\ActivityLog\Activity;
use App\Models\User;
use App\Support\ActivityLog\RequestActivityBatch;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Activity\ListActivityTool;

mutates(ListActivityTool::class);

/*
 * Every test here runs WITHOUT a Filament tenant, on purpose: the agent runs
 * inside a queued job that binds the auth user and nothing else, so a tool
 * leaning on `Filament::getTenant()` returns zero rows in production while
 * passing locally. Never call `Filament::setTenant()` in this file.
 */

/**
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function activityPayload(array $input = []): array
{
    $decoded = json_decode(app(ListActivityTool::class)->handle(new Request($input)), true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * The request boundary between two saves. `RequestActivityBatch` is a scoped
 * binding, so the framework hands the next request or queued job a fresh
 * `batch_uuid`; a test doing both saves in one process has to say so.
 */
function nextActivityRequest(): void
{
    app()->forgetInstance(RequestActivityBatch::class);
}

/**
 * Write an activity row with a chosen `batch_uuid`, so a test can state which
 * rows belong to the same save. Real saves cannot: `LogActivityAction` keeps
 * its `beforeLogging` callbacks in a static array that survives the app, so
 * once a second test has booted, the stamping closure resolves the batch from
 * a flushed container and every row gets its own uuid. Same reason
 * BatchMergedTimelineTest seeds its rows.
 *
 * @param  array<string, mixed>  $attributeChanges
 * @param  array<string, mixed>  $properties
 */
function seedActivityRow(Model $subject, string $event, string $batchUuid, array $attributeChanges = [], array $properties = []): void
{
    Activity::withoutGlobalScopes()->create([
        'log_name' => 'crm',
        'description' => $event,
        'event' => $event,
        'subject_type' => $subject->getMorphClass(),
        'subject_id' => $subject->getKey(),
        'causer_type' => 'user',
        'causer_id' => auth()->id(),
        'attribute_changes' => $attributeChanges,
        'properties' => $properties,
        'batch_uuid' => $batchUuid,
        'team_id' => $subject->getAttribute('team_id'),
    ]);
}

it('reports a name change with its old and new value', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $company = app(CreateCompany::class)->execute($user, ['name' => 'Old Co']);

    nextActivityRequest();

    app(UpdateCompany::class)->execute($user, $company, ['name' => 'New Co']);

    $payload = activityPayload([
        'record_type' => 'company',
        'record_id' => (string) $company->getKey(),
    ]);

    expect($payload['data'])->toHaveCount(2)
        ->and($payload['data'][0]['event'])->toBe('updated')
        ->and($payload['data'][0]['by'])->toBe($user->name)
        ->and($payload['data'][0]['record']['id'])->toBe((string) $company->getKey())
        ->and($payload['data'][0]['record']['name'])->toBe('New Co')
        ->and($payload['data'][0]['changes'])->toBe([
            ['field' => 'Name', 'old' => 'Old Co', 'new' => 'New Co'],
        ])
        ->and($payload['data'][1]['event'])->toBe('created');

    $block = $payload['display_block'];

    expect($block['block'])->toBe('records_table')
        ->and($block['type'])->toBe('activity')
        ->and($block['core'])->toBe('record')
        ->and(array_map(static fn (array $column): string => $column['key'], $block['columns']))
        ->toBe(['when', 'record', 'who', 'what'])
        ->and($block['total'])->toBe(2)
        ->and($block['rows'][0]['url'])->toBe("/r/company/{$company->getKey()}")
        ->and($block['rows'][0]['type'])->toBe('company')
        ->and($block['rows'][0]['cells']['record'])->toBe('New Co')
        ->and($block['rows'][0]['cells']['who'])->toBe($user->name)
        ->and($block['rows'][0]['cells']['what'])->toBe('Name: Old Co → New Co')
        ->and($block['rows'][0]['cells']['when'])->not->toBe('')
        ->and($block['rows'][1]['cells']['what'])->toBe('Created');
});

it('never shows another team\'s activity', function (): void {
    $intruder = User::factory()->withPersonalTeam()->create();
    $owner = User::factory()->withPersonalTeam()->create();

    $this->actingAs($owner);
    $secret = app(CreateCompany::class)->execute($owner, ['name' => 'Secret Co']);

    nextActivityRequest();

    app(UpdateCompany::class)->execute($owner, $secret, ['name' => 'Secret Renamed']);

    $this->actingAs($intruder);
    app(CreateCompany::class)->execute($intruder, ['name' => 'Own Co']);

    $payload = activityPayload();

    $names = array_map(static fn (array $entry): string => $entry['record']['name'], $payload['data']);

    expect($names)->toBe(['Own Co'])
        ->and(json_encode($payload))->not->toContain('Secret');
});

it('rejects a record id belonging to another team', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $this->actingAs($owner);
    $secret = app(CreateCompany::class)->execute($owner, ['name' => 'Secret Co']);

    $intruder = User::factory()->withPersonalTeam()->create();
    $this->actingAs($intruder);

    $payload = activityPayload([
        'record_type' => 'company',
        'record_id' => (string) $secret->getKey(),
    ]);

    expect($payload)->toHaveKey('error')
        ->and($payload['error'])->toContain('not found')
        ->and($payload)->not->toHaveKey('data');
});

it('reads a custom field change from the label the writer stored', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    app(CreateCustomField::class)->execute($user, [
        'entity_type' => 'company',
        'name' => 'Lead source',
        'code' => 'lead_source',
        'type' => 'text',
    ]);

    $company = app(CreateCompany::class)->execute($user, ['name' => 'Acme']);

    nextActivityRequest();

    app(UpdateCompany::class)->execute($user, $company, [
        'name' => 'Acme Inc',
        'custom_fields' => ['lead_source' => 'Referral'],
    ]);

    $payload = activityPayload([
        'record_type' => 'company',
        'record_id' => (string) $company->getKey(),
    ]);

    /** @var list<array{field: string, old: string|null, new: string|null}> $changes */
    $changes = array_merge(...array_column($payload['data'], 'changes'));

    expect($changes)->toContain(['field' => 'Lead source', 'old' => null, 'new' => 'Referral'])
        ->and($changes)->toContain(['field' => 'Name', 'old' => 'Acme', 'new' => 'Acme Inc']);
});

it('collapses one save\'s native and custom-field rows into a single entry', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $company = app(CreateCompany::class)->execute($user, ['name' => 'Acme']);
    Activity::withoutGlobalScopes()->delete();

    $batch = '11111111-1111-1111-1111-111111111111';
    seedActivityRow($company, 'updated', $batch, ['attributes' => ['name' => 'Acme Inc'], 'old' => ['name' => 'Acme']]);
    seedActivityRow($company, 'custom_field_changes', $batch, [], ['custom_field_changes' => [[
        'code' => 'lead_source',
        'label' => 'Lead source',
        'old' => ['value' => null, 'label' => 'none'],
        'new' => ['value' => 'referral', 'label' => 'Referral'],
    ]]]);

    $payload = activityPayload();

    expect($payload['data'])->toHaveCount(1)
        ->and($payload['data'][0]['event'])->toBe('updated')
        ->and($payload['data'][0]['changes'])->toBe([
            ['field' => 'Name', 'old' => 'Acme', 'new' => 'Acme Inc'],
            ['field' => 'Lead source', 'old' => null, 'new' => 'Referral'],
        ]);
});

it('keeps two records touched by one job as separate entries', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $first = app(CreateCompany::class)->execute($user, ['name' => 'First Co']);
    $second = app(CreateCompany::class)->execute($user, ['name' => 'Second Co']);
    Activity::withoutGlobalScopes()->delete();

    // One queued job holds one batch_uuid for every record it saves, so the
    // subject has to be part of the grouping key.
    $batch = '22222222-2222-2222-2222-222222222222';
    seedActivityRow($first, 'updated', $batch, ['attributes' => ['name' => 'First Renamed'], 'old' => ['name' => 'First Co']]);
    seedActivityRow($second, 'updated', $batch, ['attributes' => ['name' => 'Second Renamed'], 'old' => ['name' => 'Second Co']]);

    $payload = activityPayload();

    $names = array_map(static fn (array $entry): string => $entry['record']['name'], $payload['data']);

    expect($payload['data'])->toHaveCount(2)
        ->and($names)->toContain('First Co', 'Second Co');
});

it('reports a deletion, naming the record it soft-deleted', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $company = app(CreateCompany::class)->execute($user, ['name' => 'Gone Co']);

    nextActivityRequest();

    app(DeleteCompany::class)->execute($user, $company);

    $payload = activityPayload();

    expect($payload['data'][0]['event'])->toBe('deleted')
        ->and($payload['data'][0]['record']['name'])->toBe('Gone Co')
        ->and($payload['display_block']['rows'][0]['cells']['what'])->toBe('Deleted');
});

it('drops activity whose record was force-deleted', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $kept = app(CreateCompany::class)->execute($user, ['name' => 'Kept Co']);
    $purged = app(CreateCompany::class)->execute($user, ['name' => 'Purged Co']);

    // Nothing cascades activity rows, so a force delete leaves history behind
    // for a record that can no longer be named or opened.
    $purged->forceDelete();

    $payload = activityPayload();

    $names = array_map(static fn (array $entry): string => $entry['record']['name'], $payload['data']);

    expect($names)->toBe(['Kept Co'])
        ->and($payload['display_block']['total'])->toBe(1);
});

it('ignores activity older than the requested window', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $this->travelTo(now()->subDays(20));
    app(CreateCompany::class)->execute($user, ['name' => 'Ancient Co']);
    $this->travelBack();

    expect(activityPayload(['days' => 7])['data'])->toBe([])
        ->and(activityPayload(['days' => 30])['data'])->toHaveCount(1);
});

it('renders no table when nothing changed in the window', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $payload = activityPayload();

    expect($payload['data'])->toBe([])
        ->and($payload)->not->toHaveKey('display_block');
});
