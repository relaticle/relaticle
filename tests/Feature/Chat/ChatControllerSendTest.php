<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\OnboardingDataSource;
use App\Enums\OnboardingUseCase;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceSetupStep;
use App\Features\OnboardSeed;
use App\Features\SetupConversation;
use App\Filament\Pages\CreateWorkspace;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Http\Controllers\ChatController;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use Tests\Helpers\ChatDocument;

mutates(ChatController::class, ProcessChatMessage::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $this->workspace->getKey()], [
        'workspace_id' => $this->workspace->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
});

it('returns 404 when the supplied conversation_id does not exist', function (): void {
    $clientId = '019dded5-aaaa-7bbb-8ccc-444400000000';

    $this->postJson(route('chat.send'), [
        'document' => ChatDocument::fromText('hi'),
        'conversation_id' => $clientId,
    ])->assertStatus(404);

    expect(DB::table('agent_conversations')->where('id', $clientId)->exists())->toBeFalse();
});

it('rejects a client-supplied conversation_id that already belongs to another user', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $sharedId = '019dded5-bbbb-7bbb-8ccc-555500000000';

    DB::table('agent_conversations')->insert([
        'id' => $sharedId,
        'participant_type' => 'user',
        'participant_id' => $owner->getKey(),
        'workspace_id' => $owner->currentWorkspace->getKey(),
        'title' => 'private',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson(route('chat.send'), [
        'document' => ChatDocument::fromText('hi'),
        'conversation_id' => $sharedId,
    ]);

    $response->assertStatus(403);
});

it('rejects a client-supplied conversation_id pinned to a different workspace', function (): void {
    $otherWorkspace = Workspace::factory()->create();
    $crossWorkspaceId = '019dded5-cccc-7bbb-8ccc-666600000000';

    DB::table('agent_conversations')->insert([
        'id' => $crossWorkspaceId,
        'participant_type' => 'user',
        'participant_id' => $this->user->getKey(),
        'workspace_id' => $otherWorkspace->getKey(),
        'title' => 'cross-workspace',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson(route('chat.send'), [
        'document' => ChatDocument::fromText('hi'),
        'conversation_id' => $crossWorkspaceId,
    ]);

    $response->assertStatus(403);
});

it('rejects malformed conversation_id', function (): void {
    $response = $this->postJson(route('chat.send'), [
        'document' => ChatDocument::fromText('hi'),
        'conversation_id' => 'not-a-uuid',
    ]);

    $response->assertStatus(422);
});

it('returns 422 when conversation_id is missing', function (): void {
    $response = $this->postJson(route('chat.send'), [
        'document' => ChatDocument::fromText('hi'),
    ]);

    $response->assertStatus(422);
});

it('accepts a valid mentions array', function (): void {
    Queue::fake();
    $company = Company::factory()->for($this->workspace)->create(['name' => 'Acme Corp']);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'seed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson(route('chat.send'), [
        'conversation_id' => $conversationId,
        'document' => ChatDocument::fromText('Tell me about ', [
            ['type' => 'company', 'id' => $company->id, 'label' => 'Acme Corp'],
        ]),
    ]);

    $response->assertOk();
    Queue::assertPushed(ProcessChatMessage::class, function (ProcessChatMessage $job) use ($company): bool {
        return count($job->mentions) === 1
            && $job->mentions[0]['id'] === $company->id
            && $job->mentions[0]['type'] === 'company'
            && $job->mentions[0]['label'] === 'Acme Corp';
    });
});

it('silently drops mentions whose records do not belong to the current workspace', function (): void {
    Queue::fake();
    $otherWorkspace = Workspace::factory()->create();
    $foreignCompany = Company::factory()->for($otherWorkspace)->create(['name' => 'Foreign Co']);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'seed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->postJson(route('chat.send'), [
        'conversation_id' => $conversationId,
        'document' => ChatDocument::fromText('Tell me about that company ', [
            ['type' => 'company', 'id' => $foreignCompany->id, 'label' => 'Foreign Co'],
        ]),
    ]);

    $response->assertOk();
    Queue::assertPushed(ProcessChatMessage::class, function (ProcessChatMessage $job): bool {
        return $job->mentions === [];
    });
});

function seedSendableConversation(): string
{
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) test()->user->getKey(),
        'workspace_id' => test()->workspace->getKey(),
        'title' => 'seed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $conversationId;
}

it('records which setup button sent a message', function (): void {
    Queue::fake();
    $conversationId = seedSendableConversation();

    $this->postJson(route('chat.send'), [
        'conversation_id' => $conversationId,
        'document' => ChatDocument::fromText('Pick who to work on first'),
        'next_step' => 'pick_first',
    ])->assertOk();

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool => $job->nextStep === 'pick_first');

    CrmAssistant::fake(['Done.']);
    Queue::pushed(ProcessChatMessage::class)->sole()->handle(resolve(CreditService::class));

    $userRow = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'user')
        ->sole();

    expect(json_decode((string) $userRow->meta, true)['next_step'])->toBe('pick_first');
});

it('leaves the user row without a next step when no button sent the message', function (): void {
    Queue::fake();
    $conversationId = seedSendableConversation();

    $this->postJson(route('chat.send'), [
        'conversation_id' => $conversationId,
        'document' => ChatDocument::fromText('Typed by hand'),
    ])->assertOk();

    CrmAssistant::fake(['Done.']);
    Queue::pushed(ProcessChatMessage::class)->sole()->handle(resolve(CreditService::class));

    $userRow = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'user')
        ->sole();

    expect(json_decode((string) $userRow->meta, true))->not->toHaveKey('next_step');
});

it('rejects a malformed next step id', function (string $nextStep): void {
    Queue::fake();

    $this->postJson(route('chat.send'), [
        'conversation_id' => seedSendableConversation(),
        'document' => ChatDocument::fromText('hi'),
        'next_step' => $nextStep,
    ])->assertStatus(422)->assertJsonValidationErrors('next_step');

    Queue::assertNothingPushed();
})->with([
    'spaces and punctuation' => ['Pick First!'],
    'uppercase' => ['PICK_FIRST'],
    'too long' => [str_repeat('a', 41)],
]);

it('runs a job serialized before the next step and claim key existed', function (): void {
    Queue::fake();
    $conversationId = seedSendableConversation();

    $this->postJson(route('chat.send'), [
        'conversation_id' => $conversationId,
        'document' => ChatDocument::fromText('Queued before the deploy'),
    ])->assertOk();

    $payload = Queue::pushed(ProcessChatMessage::class)->sole()->__serialize();
    unset($payload['nextStep'], $payload['claimKey']);

    $legacy = (new ReflectionClass(ProcessChatMessage::class))->newInstanceWithoutConstructor();
    $legacy->__unserialize($payload);

    CrmAssistant::fake(['Done.']);
    $legacy->handle(resolve(CreditService::class));

    $userRow = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'user')
        ->sole();

    expect((new ReflectionProperty($legacy, 'nextStep'))->isInitialized($legacy))->toBeFalse()
        ->and(json_decode((string) $userRow->meta, true))->not->toHaveKey('next_step');
});

function sendSourceAnswer(string $conversationId, OnboardingDataSource $source): TestResponse
{
    return test()->postJson(route('chat.send'), [
        'conversation_id' => $conversationId,
        'document' => ChatDocument::fromText($source->getLabel()),
        'next_step' => $source->buttonId(),
    ]);
}

function sampleCompanyCount(Workspace $workspace): int
{
    return Company::query()
        ->withoutGlobalScopes()
        ->where('workspace_id', $workspace->getKey())
        ->where('creation_source', CreationSource::SAMPLE)
        ->count();
}

describe('answering where the clients live', function (): void {
    beforeEach(function (): void {
        Feature::define(SetupConversation::class, true);
        Feature::define(OnboardSeed::class, true);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        livewire(CreateWorkspace::class)->fillForm(['name' => 'Northwind Studio'])->call('register');

        $this->workspace = $this->user->fresh()->currentWorkspace;
        $this->workspace->forceFill(['onboarding_step' => null, 'onboarding_use_case' => OnboardingUseCase::Sales])->save();
        Filament::setTenant($this->workspace);
        $this->conversationId = $this->workspace->setupConversation->id;
    });

    it('stores the answer before the turn reads the workspace', function (): void {
        config()->set('queue.connections.redis-chat', ['driver' => 'sync']);
        CrmAssistant::fake(['Upload your file.']);

        sendSourceAnswer($this->conversationId, OnboardingDataSource::Spreadsheet)->assertOk();

        CrmAssistant::assertPrompted(fn ($prompt): bool => $prompt->prompt === OnboardingDataSource::Spreadsheet->getLabel()
            && str_contains($prompt->agent->dynamicInstructions(), "data_source: spreadsheet\n"));
    });

    describe('on a faked queue', function (): void {
        beforeEach(function (): void {
            Queue::fake();
        });

        it('sends the answer label as the user message with its answer id', function (): void {
            sendSourceAnswer($this->conversationId, OnboardingDataSource::Spreadsheet)->assertOk();

            expect($this->workspace->fresh()->onboarding_data_source)->toBe(OnboardingDataSource::Spreadsheet);
            Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool => $job->nextStep === 'source_spreadsheet');
        });

        it('seeds the sample records for the in my head answer only', function (OnboardingDataSource $source, bool $seeded): void {
            sendSourceAnswer($this->conversationId, $source)->assertOk();

            expect($this->workspace->fresh()->onboarding_data_source)->toBe($source)
                ->and(sampleCompanyCount($this->workspace))->toBe($seeded ? 4 : 0);
        })->with([
            'in my head' => [OnboardingDataSource::Memory, true],
            'my inbox' => [OnboardingDataSource::Inbox, false],
            'a spreadsheet' => [OnboardingDataSource::Spreadsheet, false],
            'another crm' => [OnboardingDataSource::OtherCrm, false],
            'an assistant' => [OnboardingDataSource::Assistant, false],
        ]);

        it('seeds the sample set of the workspace use case', function (): void {
            $this->workspace->forceFill(['onboarding_use_case' => OnboardingUseCase::Recruiting])->save();

            sendSourceAnswer($this->conversationId, OnboardingDataSource::Memory)->assertOk();

            expect(Company::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->getKey())->pluck('name')->sort()->values()->all())
                ->toBe(['Linear', 'Stripe', 'Supabase', 'Vercel']);
        });

        it('seeds nothing on top of the records the workspace already holds', function (CreationSource $source): void {
            Company::factory()->create(['workspace_id' => $this->workspace->getKey(), 'creation_source' => $source]);

            sendSourceAnswer($this->conversationId, OnboardingDataSource::Memory)->assertOk();

            expect($this->workspace->fresh()->onboarding_data_source)->toBe(OnboardingDataSource::Memory)
                ->and(Company::query()->withoutGlobalScopes()->where('workspace_id', $this->workspace->getKey())->count())->toBe(1);
        })->with([
            'its own record' => CreationSource::WEB,
            'a sample' => CreationSource::SAMPLE,
        ]);

        it('seeds nothing in an additional workspace', function (): void {
            $this->workspace->forceFill(['personal_workspace' => false])->save();

            sendSourceAnswer($this->conversationId, OnboardingDataSource::Memory)->assertOk();

            expect(sampleCompanyCount($this->workspace))->toBe(0);
        });

        it('stores nothing and seeds nothing while the wizard is still open', function (): void {
            $this->workspace->forceFill(['onboarding_step' => WorkspaceSetupStep::Invite])->save();

            sendSourceAnswer($this->conversationId, OnboardingDataSource::Memory)->assertOk();

            expect($this->workspace->fresh()->onboarding_data_source)->toBeNull()
                ->and(sampleCompanyCount($this->workspace))->toBe(0);
        });

        it('changes nothing on a second answer', function (): void {
            sendSourceAnswer($this->conversationId, OnboardingDataSource::Spreadsheet)->assertOk();
            sendSourceAnswer($this->conversationId, OnboardingDataSource::Memory)->assertOk();

            expect($this->workspace->fresh()->onboarding_data_source)->toBe(OnboardingDataSource::Spreadsheet)
                ->and(sampleCompanyCount($this->workspace))->toBe(0);
        });

        it('stores nothing and seeds nothing when the workspace is out of credits', function (): void {
            withHostedBilling();
            $this->workspace->forceFill(['hosted_free_grandfathered_at' => now()])->save();
            AiCreditBalance::query()->where('workspace_id', $this->workspace->getKey())->update(['credits_remaining' => 0]);

            sendSourceAnswer($this->conversationId, OnboardingDataSource::Memory)->assertStatus(402)->assertJsonPath('error', 'credits_exhausted');

            expect($this->workspace->fresh()->onboarding_data_source)->toBeNull()
                ->and(sampleCompanyCount($this->workspace))->toBe(0);
            Queue::assertNothingPushed();
        });

        it('keeps a member out of the owner setup conversation and stores nothing', function (): void {
            $member = User::factory()->create();
            $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
            $member->switchWorkspace($this->workspace);
            $this->actingAs($member);

            sendSourceAnswer($this->conversationId, OnboardingDataSource::Memory)->assertForbidden();

            expect($this->workspace->fresh()->onboarding_data_source)->toBeNull()
                ->and(sampleCompanyCount($this->workspace))->toBe(0);
        });

        it('ignores an answer id outside the setup conversation', function (): void {
            sendSourceAnswer(seedSendableConversation(), OnboardingDataSource::Memory)->assertOk();

            expect($this->workspace->fresh()->onboarding_data_source)->toBeNull()
                ->and(sampleCompanyCount($this->workspace))->toBe(0);
        });

        it('ignores an answer id when the setup conversation is off', function (): void {
            Feature::deactivate(SetupConversation::class);

            sendSourceAnswer($this->conversationId, OnboardingDataSource::Memory)->assertOk();

            expect($this->workspace->fresh()->onboarding_data_source)->toBeNull();
        });

        it('ignores a next step that is not an answer', function (): void {
            $this->postJson(route('chat.send'), [
                'conversation_id' => $this->conversationId,
                'document' => ChatDocument::fromText('Pick who to work on first'),
                'next_step' => 'pick_first',
            ])->assertOk();

            expect($this->workspace->fresh()->onboarding_data_source)->toBeNull();
        });

        it('sends the message but stores no inbox answer when no mailbox can connect', function (): void {
            config()->set('relaticle.features.email_integration', false);
            Feature::flushCache();

            sendSourceAnswer($this->conversationId, OnboardingDataSource::Inbox)->assertOk();

            expect($this->workspace->fresh()->onboarding_data_source)->toBeNull();
            Queue::assertPushed(ProcessChatMessage::class);
        });
    });
});
