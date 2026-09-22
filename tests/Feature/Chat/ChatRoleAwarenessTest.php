<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Features\Billing;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\Company\CreateCompanyTool;
use Relaticle\Chat\Tools\Company\DeleteCompanyTool;
use Relaticle\Chat\Tools\Company\UpdateCompanyTool;

beforeEach(function (): void {
    Bus::fake();

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;

    $this->conversationId = '019dfa00-5555-7000-8000-0000000000e1';
    DB::table('agent_conversations')->insert([
        'id' => $this->conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->owner->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actAs = function (WorkspaceRole $role): User {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user, ['role' => $role->value]);
        $user->switchWorkspace($this->workspace);
        $user = $user->fresh();

        $this->actingAs($user);
        Auth::guard('web')->setUser($user);
        Filament::setTenant($this->workspace);

        return $user;
    };

    $this->tool = function (string $class): object {
        $tool = resolve($class);
        $tool->setConversationId($this->conversationId);
        $tool->setTurnId('01TURNAAAAAAAAAAAAAAAAAAAE');

        return $tool;
    };
});

test('refuses a viewer the create tool and proposes nothing', function (): void {
    ($this->actAs)(WorkspaceRole::Viewer);

    $result = ($this->tool)(CreateCompanyTool::class)->handle(new Request(['records' => [['name' => 'Viewer Co']]]));

    expect($result)->toContain('role does not allow')
        ->and($result)->toContain('Do not link to any page')
        ->and(PendingAction::query()->count())->toBe(0);
});

test('refuses a viewer the update tool and proposes nothing', function (): void {
    $company = Company::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    ($this->actAs)(WorkspaceRole::Viewer);

    $result = ($this->tool)(UpdateCompanyTool::class)->handle(new Request([
        'records' => [['id' => (string) $company->getKey(), 'name' => 'Renamed']],
    ]));

    expect($result)->toContain('role does not allow')
        ->and($result)->toContain('Do not link to any page')
        ->and(PendingAction::query()->count())->toBe(0);
});

test('refuses a viewer the delete tool and proposes nothing', function (): void {
    $company = Company::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    ($this->actAs)(WorkspaceRole::Viewer);

    $result = ($this->tool)(DeleteCompanyTool::class)->handle(new Request([
        'ids' => [(string) $company->getKey()],
    ]));

    expect($result)->toContain('role does not allow')
        ->and($result)->toContain('Do not link to any page')
        ->and(PendingAction::query()->count())->toBe(0);
});

test('still proposes a create for a member', function (): void {
    ($this->actAs)(WorkspaceRole::Member);

    $result = ($this->tool)(CreateCompanyTool::class)->handle(new Request(['records' => [['name' => 'Member Co']]]));

    expect($result)->toContain('pending_action')
        ->and(PendingAction::query()->count())->toBe(1);
});

test('offers the billing link only to a user who can manage billing', function (): void {
    Feature::define(Billing::class, true);

    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);

    $ownerUpgradeUrl = str_contains(Livewire::test(ChatInterface::class)->html(), 'upgradeUrl: null');

    ($this->actAs)(WorkspaceRole::Member);

    $memberUpgradeUrl = str_contains(Livewire::test(ChatInterface::class)->html(), 'upgradeUrl: null');

    expect($ownerUpgradeUrl)->toBeFalse()
        ->and($memberUpgradeUrl)->toBeTrue();
});
