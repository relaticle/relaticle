<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Jobs\ContinueChatMessage;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\Company\CreateCompanyTool;

mutates(CreateCompany::class);
mutates(CreateCompanyTool::class);

beforeEach(function (): void {
    Bus::fake([ContinueChatMessage::class]);

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->switchTeam($this->user->ownedTeams()->first());
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

it('persists account_owner_id when CreateCompany action receives it', function (): void {
    $company = (new CreateCompany)->execute(
        $this->user,
        ['name' => 'AI Created Co', 'account_owner_id' => $this->user->getKey()],
        CreationSource::CHAT,
    );

    expect($company->account_owner_id)->toBe($this->user->getKey());
});

it('CreateCompanyTool persists authenticated user id as account_owner_id in pending action', function (): void {
    /** @var CreateCompanyTool $tool */
    $tool = app(CreateCompanyTool::class);

    $tool->handle(new Request(['records' => [['name' => 'AI Co']]]));

    $pending = PendingAction::query()
        ->where('team_id', $this->user->currentTeam->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->action_data['account_owner_id'])->toBe($this->user->getKey());
});

it('AI-created company through pending-action approval gets owner set', function (): void {
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $this->user->getKey(),
        'team_id' => $this->user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(PendingActionService::class);

    $pending = $service->createProposal(
        user: $this->user,
        conversationId: $conversationId,
        actionClass: CreateCompany::class,
        operation: PendingActionOperation::Create,
        entityType: 'company',
        actionData: [
            'name' => 'Approved Co',
            'account_owner_id' => $this->user->getKey(),
        ],
        displayData: [
            'title' => 'Create Company',
            'summary' => 'Create company "Approved Co"',
            'fields' => [['label' => 'Name', 'value' => 'Approved Co']],
        ],
    );

    $service->approve($pending, $this->user);

    /** @var Company $company */
    $company = Company::query()->where('name', 'Approved Co')->firstOrFail();

    expect($company->account_owner_id)->toBe($this->user->getKey());
});
