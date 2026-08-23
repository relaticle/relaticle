<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Actions\Company\DeleteCompany;
use App\Actions\Company\UpdateCompany;
use App\Actions\CustomFields\CreateCustomField;
use App\Models\ActivityLog\Activity;
use App\Models\User;
use App\Support\ActivityLog\RequestActivityBatch;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Activity\ListActivityTool;

mutates(ListActivityTool::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
});

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
 * The `batch_uuid` shared by every activity row the given event wrote. Empty
 * when the rows disagree, which is what a broken merge looks like.
 *
 * @return list<string|null>
 */
function activityBatches(string $event): array
{
    return Activity::withoutGlobalScopes()
        ->where('event', $event)
        ->pluck('batch_uuid')
        ->all();
}

it('reports a name change with its old and new value', function (): void {
    $user = $this->user;

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
    $user = $this->user;

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
    $user = $this->user;

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

    // The real writer split this one save across two rows and stamped them with
    // one batch_uuid. Asserted here so a regression in the stamping shows up as
    // itself rather than as a merge that silently stops merging.
    expect(activityBatches('updated'))->toHaveCount(1)
        ->and(activityBatches('custom_field_changes'))->toBe(activityBatches('updated'));

    $payload = activityPayload();

    expect(Activity::withoutGlobalScopes()->count())->toBe(3)
        ->and($payload['data'])->toHaveCount(2)
        ->and($payload['data'][0]['event'])->toBe('updated')
        ->and($payload['data'][0]['changes'])->toBe([
            ['field' => 'Name', 'old' => 'Acme', 'new' => 'Acme Inc'],
            ['field' => 'Lead source', 'old' => null, 'new' => 'Referral'],
        ])
        ->and($payload['data'][1]['event'])->toBe('created');
});

it('keeps two records touched by one job as separate entries', function (): void {
    $user = $this->user;

    $first = app(CreateCompany::class)->execute($user, ['name' => 'First Co']);
    $second = app(CreateCompany::class)->execute($user, ['name' => 'Second Co']);

    nextActivityRequest();

    // One request or queued job holds one batch_uuid for every record it saves,
    // so the subject has to be part of the grouping key.
    app(UpdateCompany::class)->execute($user, $first, ['name' => 'First Renamed']);
    app(UpdateCompany::class)->execute($user, $second, ['name' => 'Second Renamed']);

    expect(array_unique(activityBatches('updated')))->toHaveCount(1);

    $payload = activityPayload();

    $updated = array_values(array_filter(
        $payload['data'],
        static fn (array $entry): bool => $entry['event'] === 'updated',
    ));

    expect($updated)->toHaveCount(2)
        ->and(array_column(array_column($updated, 'record'), 'id'))
        ->toEqualCanonicalizing([(string) $first->getKey(), (string) $second->getKey()]);
});

it('reports a deletion, naming the record it soft-deleted', function (): void {
    $user = $this->user;

    $company = app(CreateCompany::class)->execute($user, ['name' => 'Gone Co']);

    nextActivityRequest();

    app(DeleteCompany::class)->execute($user, $company);

    $payload = activityPayload();

    expect($payload['data'][0]['event'])->toBe('deleted')
        ->and($payload['data'][0]['record']['name'])->toBe('Gone Co')
        ->and($payload['display_block']['rows'][0]['cells']['what'])->toBe('Deleted');
});

it('drops activity whose record was force-deleted', function (): void {
    $user = $this->user;

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

it('keeps real history when every recent row points at a purged record', function (): void {
    $user = $this->user;

    $kept = app(CreateCompany::class)->execute($user, ['name' => 'Kept Co']);

    // Enough newer rows to fill the fetch on their own. Dropped after the SQL
    // limit instead of inside it, they would starve the one real entry out of
    // the answer and the assistant would report that nothing had changed.
    for ($i = 0; $i < 50; $i++) {
        app(CreateCompany::class)->execute($user, ['name' => "Purged Co {$i}"])->forceDelete();
    }

    $payload = activityPayload();

    $names = array_map(static fn (array $entry): string => $entry['record']['name'], $payload['data']);

    expect($names)->toBe(['Kept Co'])
        ->and($payload['data'][0]['record']['id'])->toBe((string) $kept->getKey())
        ->and($payload['display_block']['total'])->toBe(1);
});

it('reports the true entry count when the window holds more than one fetch', function (): void {
    $user = $this->user;

    // One request stamps all of these with a single batch_uuid, so they group by
    // subject: 51 records, 51 entries, one more than a fetch can carry.
    for ($i = 0; $i < 51; $i++) {
        app(CreateCompany::class)->execute($user, ['name' => "Co {$i}"]);
    }

    $payload = activityPayload();

    expect($payload['data'])->toHaveCount(50)
        ->and($payload['display_block']['rows'])->toHaveCount(10)
        // The footer reads "Showing :showing of :total". A count taken over the
        // returned entries would say 50 here and mislead by exactly the rows it
        // could not fetch.
        ->and($payload['display_block']['total'])->toBe(51)
        ->and($payload['total'])->toBe(51)
        ->and($payload['showing'])->toBe(count($payload['data']));
});

it('ignores activity older than the requested window', function (): void {
    $user = $this->user;

    $this->travelTo(now()->subDays(20));
    app(CreateCompany::class)->execute($user, ['name' => 'Ancient Co']);
    $this->travelBack();

    expect(activityPayload(['days' => 7])['data'])->toBe([])
        ->and(activityPayload(['days' => 30])['data'])->toHaveCount(1);
});

it('renders no table when nothing changed in the window', function (): void {
    $user = $this->user;

    $payload = activityPayload();

    expect($payload['data'])->toBe([])
        ->and($payload)->not->toHaveKey('display_block');
});
