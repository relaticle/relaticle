<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\SearchCrmTool;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(SearchCrmTool::class);

it('finds a person by their email custom field value, not just their name', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

    TenantContextService::setTenantId($team->getKey());
    $person = People::factory()->for($team)->create(['name' => 'Patrick Collison']);
    $person->update(['custom_fields' => ['emails' => ['patrick@stripe.com']]]);
    TenantContextService::setTenantId(null);

    $results = json_decode(app(SearchCrmTool::class)->handle(new Request(['query' => 'stripe'])), true);

    expect(collect($results['people'])->pluck('name'))->toContain('Patrick Collison');
});

it('never matches an option id or rich text markup that merely contains the query', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

    $statusField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->firstOrFail();

    $optionId = (string) $statusField->options->firstWhere('name', 'To do')->id;

    $task = Task::factory()->for($team)->create(['title' => 'Roadmap task']);
    $task->saveCustomFieldValue($statusField, $optionId);

    TenantContextService::setTenantId($team->getKey());
    $note = Note::factory()->for($team)->create(['title' => 'Meeting notes']);
    $note->update(['custom_fields' => ['body' => "<div data-ref=\"{$optionId}\">Follow up</div>"]]);
    TenantContextService::setTenantId(null);

    $storedStatus = DB::table('custom_field_values')
        ->where('entity_type', 'task')
        ->where('entity_id', $task->getKey())
        ->value('string_value');
    $storedBody = DB::table('custom_field_values')
        ->where('entity_type', 'note')
        ->where('entity_id', $note->getKey())
        ->value('text_value');

    expect($storedStatus)->toBe($optionId)
        ->and($storedBody)->toContain($optionId);

    $results = json_decode(app(SearchCrmTool::class)->handle(new Request(['query' => $optionId])), true);

    expect($results['tasks'])->toBeEmpty()
        ->and($results['notes'])->toBeEmpty();
});

it('never surfaces another tenant\'s matching custom field value', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $outsider = User::factory()->withPersonalTeam()->create();
    $outsiderTeam = $outsider->currentTeam;

    TenantContextService::setTenantId($outsiderTeam->getKey());
    $theirPerson = People::factory()->for($outsiderTeam)->create(['name' => 'Foreign Contact']);
    $theirPerson->update(['custom_fields' => ['emails' => ['contact@stripe.com']]]);
    TenantContextService::setTenantId(null);

    $storedEmails = DB::table('custom_field_values')
        ->where('entity_type', 'people')
        ->where('entity_id', $theirPerson->getKey())
        ->where('tenant_id', $outsiderTeam->getKey())
        ->value('json_value');

    expect($storedEmails)->toContain('stripe');

    $results = json_decode(app(SearchCrmTool::class)->handle(new Request(['query' => 'stripe'])), true);

    expect($results['people'])->toBeEmpty();
});

it('discloses truncation instead of presenting a capped list as the whole truth', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Company::factory()->count(7)->for($user->currentTeam)->create(['name' => 'Truncation Probe Co']);

    $results = json_decode(app(SearchCrmTool::class)->handle(new Request(['query' => 'Truncation Probe', 'limit' => 5])), true);

    expect($results['companies'])->toHaveCount(5)
        ->and($results['truncated']['companies'])->toBeTrue()
        ->and($results['truncated']['people'])->toBeFalse();
});

it('reports no truncation when every match fits under the limit', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Company::factory()->count(2)->for($user->currentTeam)->create(['name' => 'Fits Under Cap Co']);

    $results = json_decode(app(SearchCrmTool::class)->handle(new Request(['query' => 'Fits Under Cap', 'limit' => 5])), true);

    expect($results['companies'])->toHaveCount(2)
        ->and($results['truncated']['companies'])->toBeFalse();
});
