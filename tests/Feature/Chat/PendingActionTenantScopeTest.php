<?php

declare(strict_types=1);

use App\Actions\Task\CreateTask;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\CustomFields\Services\TenantContextService;

beforeEach(function (): void {
    Bus::fake();
});

/**
 * Regression for the chat-approve cross-tenant custom-field write (and the 504 it
 * caused at scale).
 *
 * When approve() runs there may be no resolvable tenant context — so the custom-fields
 * TenantScope no-ops and saveCustomFields() iterates EVERY tenant's field definitions.
 * PendingActionService::approve() sets the tenant context from the action's team before
 * executing.
 *
 * The test invokes the service directly with the tenant context torn down, which
 * faithfully reproduces the no-tenant request (an HTTP feature test cannot — the
 * test process keeps a Filament tenant resolved, masking the bug).
 */
it('scopes custom-field writes to the action tenant when approving a create', function (): void {
    // A second tenant whose Task custom fields must never be touched.
    $otherTeam = User::factory()->withPersonalTeam()->create()->currentTeam;

    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;

    $ownTaskFieldCount = CustomField::query()->withoutGlobalScopes()
        ->where('entity_type', 'task')
        ->where('tenant_id', $team->getKey())
        ->count();

    $globalTaskFieldCount = CustomField::query()->withoutGlobalScopes()
        ->where('entity_type', 'task')
        ->count();

    // Preconditions: both tenants own Task fields, so an unscoped write would leak.
    expect($ownTaskFieldCount)->toBeGreaterThan(0)
        ->and($globalTaskFieldCount)->toBeGreaterThan($ownTaskFieldCount);

    // An empty custom_fields map still drives saveCustomFields() across every defined field.
    $pending = PendingAction::query()->create([
        'team_id' => $team->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => null,
        'message_id' => null,
        'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'Tenant Scoped Task', 'custom_fields' => []],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    // Reproduce the real request condition: no resolvable tenant when the action runs.
    Filament::setTenant(null);
    TenantContextService::setTenantId(null);

    app(PendingActionService::class)->approve($pending, $user);

    $task = Task::query()->withoutGlobalScopes()
        ->where('team_id', $team->getKey())
        ->where('title', 'Tenant Scoped Task')
        ->sole();

    $otherTenantFieldIds = CustomField::query()->withoutGlobalScopes()
        ->where('tenant_id', $otherTeam->getKey())
        ->pluck('id');

    // Correctness: not a single value row written against another tenant's fields.
    $leaked = CustomFieldValue::query()->withoutGlobalScopes()
        ->where('entity_id', $task->getKey())
        ->whereIn('custom_field_id', $otherTenantFieldIds)
        ->count();

    expect($leaked)->toBe(0);

    // Bound: the write touches at most this tenant's own fields, never the global set.
    $written = CustomFieldValue::query()->withoutGlobalScopes()
        ->where('entity_id', $task->getKey())
        ->count();

    expect($written)->toBeLessThanOrEqual($ownTaskFieldCount);
});
