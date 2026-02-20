<?php

declare(strict_types=1);

use App\Enums\CustomFields\CompanyField;
use App\Models\AiSummary;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\RecordContextBuilder;
use App\Services\AI\RecordSummaryService;
use Filament\Facades\Filament;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;

beforeEach(function () {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

describe('RecordContextBuilder', function () {
    it('builds context for a company', function () {
        $company = Company::factory()
            ->for($this->user->currentTeam)
            ->create(['name' => 'Test Company']);

        $builder = app(RecordContextBuilder::class);
        $context = $builder->buildContext($company);

        expect($context)
            ->toHaveKey('entity_type', 'Company')
            ->toHaveKey('name', 'Test Company')
            ->toHaveKey('basic_info')
            ->toHaveKey('relationships')
            ->toHaveKey('notes')
            ->toHaveKey('tasks');
    });

    it('builds context for a person', function () {
        $person = People::factory()
            ->for($this->user->currentTeam)
            ->create(['name' => 'John Doe']);

        $builder = app(RecordContextBuilder::class);
        $context = $builder->buildContext($person);

        expect($context)
            ->toHaveKey('entity_type', 'Person')
            ->toHaveKey('name', 'John Doe')
            ->toHaveKey('basic_info')
            ->toHaveKey('notes')
            ->toHaveKey('tasks');
    });

    it('builds context for an opportunity', function () {
        $company = Company::factory()
            ->for($this->user->currentTeam)
            ->create();

        $opportunity = Opportunity::factory()
            ->for($this->user->currentTeam)
            ->for($company)
            ->create(['name' => 'Test Deal']);

        $builder = app(RecordContextBuilder::class);
        $context = $builder->buildContext($opportunity);

        expect($context)
            ->toHaveKey('entity_type', 'Opportunity')
            ->toHaveKey('basic_info')
            ->toHaveKey('company', $company->name)
            ->toHaveKey('notes')
            ->toHaveKey('tasks');
    });

    it('includes related notes in context', function () {
        $company = Company::factory()
            ->for($this->user->currentTeam)
            ->create();

        $notes = Note::factory()
            ->for($this->user->currentTeam)
            ->count(3)
            ->create();

        $company->notes()->attach($notes);

        $builder = app(RecordContextBuilder::class);
        $context = $builder->buildContext($company->fresh());

        expect($context['notes'])
            ->toHaveKey('items')
            ->toHaveKey('showing', 3)
            ->toHaveKey('total', 3)
            ->toHaveKey('has_more', false)
            ->and($context['notes']['items'])->toHaveCount(3);
    });

    it('includes related tasks in context', function () {
        $company = Company::factory()
            ->for($this->user->currentTeam)
            ->create();

        $tasks = Task::factory()
            ->for($this->user->currentTeam)
            ->count(2)
            ->create();

        $company->tasks()->attach($tasks);

        $builder = app(RecordContextBuilder::class);
        $context = $builder->buildContext($company->fresh());

        expect($context['tasks'])
            ->toHaveKey('items')
            ->toHaveKey('showing', 2)
            ->toHaveKey('total', 2)
            ->toHaveKey('has_more', false)
            ->and($context['tasks']['items'])->toHaveCount(2);
    });

    it('limits related records and shows pagination info', function () {
        $company = Company::factory()
            ->for($this->user->currentTeam)
            ->create();

        $notes = Note::factory()
            ->for($this->user->currentTeam)
            ->count(15)
            ->create();

        $company->notes()->attach($notes);

        $builder = app(RecordContextBuilder::class);
        $context = $builder->buildContext($company->fresh());

        expect($context['notes'])
            ->toHaveKey('showing', 10)
            ->toHaveKey('total', 15)
            ->toHaveKey('has_more', true)
            ->and($context['notes']['items'])->toHaveCount(10);
    });

    it('handles multi-value custom fields as strings in basic info', function () {
        $company = Company::factory()
            ->for($this->user->currentTeam)
            ->create();

        $domainsField = CustomField::query()
            ->where('code', CompanyField::DOMAINS->value)
            ->forEntity(Company::class)
            ->firstOrFail();

        CustomFieldValue::forceCreate([
            'tenant_id' => $this->user->currentTeam->getKey(),
            'entity_type' => 'company',
            'entity_id' => $company->getKey(),
            'custom_field_id' => $domainsField->getKey(),
            'json_value' => ['example.com', 'test.org'],
        ]);

        $builder = app(RecordContextBuilder::class);
        $context = $builder->buildContext($company);

        expect($context['basic_info']['domain'])
            ->toBeString()
            ->toBe('example.com, test.org');
    });

    it('throws exception for unsupported model', function () {
        $builder = app(RecordContextBuilder::class);
        $builder->buildContext(new User);
    })->throws(InvalidArgumentException::class);
});

describe('RecordSummaryService', function () {
    it('generates and caches a summary for a company', function () {
        Prism::fake([
            TextResponseFake::make()
                ->withText('Test Company is a promising lead with recent engagement.')
                ->withUsage(new Usage(100, 50))
                ->withFinishReason(FinishReason::Stop),
        ]);

        $company = Company::factory()
            ->for($this->user->currentTeam)
            ->create();

        $service = app(RecordSummaryService::class);
        $summary = $service->getSummary($company);

        expect($summary)
            ->toBeInstanceOf(AiSummary::class)
            ->summary->toBe('Test Company is a promising lead with recent engagement.')
            ->model_used->toBe('claude-haiku-4-5')
            ->prompt_tokens->toBe(100)
            ->completion_tokens->toBe(50);

        $this->assertDatabaseHas('ai_summaries', [
            'summarizable_type' => $company->getMorphClass(),
            'summarizable_id' => $company->getKey(),
            'team_id' => $this->user->currentTeam->getKey(),
        ]);
    });

    it('returns cached summary when available', function () {
        $company = Company::factory()
            ->for($this->user->currentTeam)
            ->create();

        $cached = AiSummary::create([
            'team_id' => $this->user->currentTeam->getKey(),
            'summarizable_type' => $company->getMorphClass(),
            'summarizable_id' => $company->getKey(),
            'summary' => 'Cached summary text',
            'model_used' => 'claude-haiku-4-5',
            'prompt_tokens' => 50,
            'completion_tokens' => 25,
        ]);

        $service = app(RecordSummaryService::class);
        $summary = $service->getSummary($company->fresh());

        expect($summary->id)->toBe($cached->id)
            ->and($summary->summary)->toBe('Cached summary text');
    });

    it('regenerates summary when requested', function () {
        $company = Company::factory()
            ->for($this->user->currentTeam)
            ->create();

        AiSummary::create([
            'team_id' => $this->user->currentTeam->getKey(),
            'summarizable_type' => $company->getMorphClass(),
            'summarizable_id' => $company->getKey(),
            'summary' => 'Old cached summary',
            'model_used' => 'claude-haiku-4-5',
            'prompt_tokens' => 50,
            'completion_tokens' => 25,
        ]);

        Prism::fake([
            TextResponseFake::make()
                ->withText('New regenerated summary')
                ->withUsage(new Usage(100, 50))
                ->withFinishReason(FinishReason::Stop),
        ]);

        $service = app(RecordSummaryService::class);
        $summary = $service->getSummary($company->fresh(), regenerate: true);

        expect($summary->summary)->toBe('New regenerated summary');

        $this->assertDatabaseCount('ai_summaries', 1);
        $this->assertDatabaseHas('ai_summaries', [
            'summary' => 'New regenerated summary',
        ]);
    });

    it('generates summary for a person', function () {
        Prism::fake([
            TextResponseFake::make()
                ->withText('John is a key decision maker at Acme Corp.')
                ->withUsage(new Usage(80, 40))
                ->withFinishReason(FinishReason::Stop),
        ]);

        $person = People::factory()
            ->for($this->user->currentTeam)
            ->create(['name' => 'John Doe']);

        $service = app(RecordSummaryService::class);
        $summary = $service->getSummary($person);

        expect($summary->summary)->toBe('John is a key decision maker at Acme Corp.');
    });

    it('generates summary for an opportunity', function () {
        Prism::fake([
            TextResponseFake::make()
                ->withText('High-value opportunity in negotiation stage.')
                ->withUsage(new Usage(90, 45))
                ->withFinishReason(FinishReason::Stop),
        ]);

        $company = Company::factory()
            ->for($this->user->currentTeam)
            ->create();

        $opportunity = Opportunity::factory()
            ->for($this->user->currentTeam)
            ->for($company)
            ->create();

        $service = app(RecordSummaryService::class);
        $summary = $service->getSummary($opportunity);

        expect($summary->summary)->toBe('High-value opportunity in negotiation stage.');
    });
});

describe('HasAiSummary trait', function () {
    it('provides aiSummary relationship on Company', function () {
        $company = Company::factory()
            ->for($this->user->currentTeam)
            ->create();

        expect($company->aiSummary())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphOne::class);
    });

    it('can invalidate summary directly via trait method', function () {
        $company = Company::factory()
            ->for($this->user->currentTeam)
            ->create();

        AiSummary::create([
            'team_id' => $this->user->currentTeam->getKey(),
            'summarizable_type' => $company->getMorphClass(),
            'summarizable_id' => $company->getKey(),
            'summary' => 'Test summary',
            'model_used' => 'claude-haiku-4-5',
            'prompt_tokens' => 50,
            'completion_tokens' => 25,
        ]);

        $this->assertDatabaseHas('ai_summaries', [
            'summarizable_type' => $company->getMorphClass(),
            'summarizable_id' => $company->getKey(),
        ]);

        $company->invalidateAiSummary();

        $this->assertDatabaseMissing('ai_summaries', [
            'summarizable_type' => $company->getMorphClass(),
            'summarizable_id' => $company->getKey(),
        ]);
    });

    it('can invalidate summary for person', function () {
        $person = People::factory()
            ->for($this->user->currentTeam)
            ->create();

        AiSummary::create([
            'team_id' => $this->user->currentTeam->getKey(),
            'summarizable_type' => $person->getMorphClass(),
            'summarizable_id' => $person->getKey(),
            'summary' => 'Test summary',
            'model_used' => 'claude-haiku-4-5',
            'prompt_tokens' => 50,
            'completion_tokens' => 25,
        ]);

        $person->invalidateAiSummary();

        $this->assertDatabaseMissing('ai_summaries', [
            'summarizable_type' => $person->getMorphClass(),
            'summarizable_id' => $person->getKey(),
        ]);
    });

    it('can invalidate summary for opportunity', function () {
        $company = Company::factory()
            ->for($this->user->currentTeam)
            ->create();

        $opportunity = Opportunity::factory()
            ->for($this->user->currentTeam)
            ->for($company)
            ->create();

        AiSummary::create([
            'team_id' => $this->user->currentTeam->getKey(),
            'summarizable_type' => $opportunity->getMorphClass(),
            'summarizable_id' => $opportunity->getKey(),
            'summary' => 'Test summary',
            'model_used' => 'claude-haiku-4-5',
            'prompt_tokens' => 50,
            'completion_tokens' => 25,
        ]);

        $opportunity->invalidateAiSummary();

        $this->assertDatabaseMissing('ai_summaries', [
            'summarizable_type' => $opportunity->getMorphClass(),
            'summarizable_id' => $opportunity->getKey(),
        ]);
    });
});
