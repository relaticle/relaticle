<?php

declare(strict_types=1);

use App\Console\Commands\ConfigurePipelineCommand;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Relations\Relation;

mutates(ConfigurePipelineCommand::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);

    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

/**
 * @param  class-string  $model
 */
function pipelineField(string $model, string $code): ?CustomField
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', test()->team->getKey())
        ->where('entity_type', Relation::getMorphAlias($model))
        ->where('code', $code)
        ->first();
}

/**
 * @return list<string>
 */
function pipelineOptionNames(CustomField $field): array
{
    return CustomFieldOption::query()
        ->withoutGlobalScopes()
        ->where('custom_field_id', $field->getKey())
        ->orderBy('sort_order')
        ->pluck('name')
        ->all();
}

it('replaces the upstream stages with ours, in board order and with colors', function (): void {
    $this->artisan('crm:configurar-pipeline', ['team' => $this->team->slug])->assertSuccessful();

    $stage = pipelineField(Opportunity::class, 'stage');
    $stages = config('crm.opportunity.stages');

    expect($stage->name)->toBe('Etapa')
        ->and($stage->settings->enable_option_colors)->toBeTrue()
        ->and(pipelineOptionNames($stage))->toBe(array_keys($stages));

    $colors = CustomFieldOption::query()
        ->withoutGlobalScopes()
        ->where('custom_field_id', $stage->getKey())
        ->get()
        ->mapWithKeys(fn (CustomFieldOption $option): array => [$option->name => $option->settings->color])
        ->all();

    expect($colors)->toEqual($stages);
});

it('keeps opportunities in an upstream stage that becomes one of ours', function (): void {
    $stage = pipelineField(Opportunity::class, 'stage');
    $prospecting = $stage->options->firstWhere('name', 'Prospecting');

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();
    $opportunity->saveCustomFieldValue($stage, $prospecting->getKey());

    $this->artisan('crm:configurar-pipeline', ['team' => $this->team->getKey()])->assertSuccessful();

    $renamed = CustomFieldOption::query()->withoutGlobalScopes()->find($prospecting->getKey());

    expect($renamed?->name)->toBe('Oportunidad')
        ->and($opportunity->fresh()->getCustomFieldValue($stage->fresh()))->toBe($prospecting->getKey());
});

it('keeps an upstream stage that still has opportunities and warns about it', function (): void {
    $stage = pipelineField(Opportunity::class, 'stage');
    $needsAnalysis = $stage->options->firstWhere('name', 'Needs Analysis');

    Opportunity::factory()
        ->recycle([$this->user, $this->team])
        ->create()
        ->saveCustomFieldValue($stage, $needsAnalysis->getKey());

    $this->artisan('crm:configurar-pipeline', ['team' => $this->team->slug])
        ->expectsOutputToContain('Needs Analysis')
        ->assertSuccessful();

    expect(pipelineOptionNames($stage))->toBe([...array_keys(config('crm.opportunity.stages')), 'Needs Analysis']);
});

it('renames the fields Relaticle creates and prices opportunities in euros', function (): void {
    $this->artisan('crm:configurar-pipeline', ['team' => $this->team->slug])->assertSuccessful();

    $amount = pipelineField(Opportunity::class, 'amount');

    expect($amount->name)->toBe('Valor')
        ->and($amount->settings->additional['currency_code'] ?? null)->toBe('EUR')
        ->and(pipelineField(Opportunity::class, 'close_date')->name)->toBe('Fecha de cierre estimada')
        ->and(pipelineField(Company::class, 'domains')->name)->toBe('Web');
});

it('creates the fields the team needs', function (): void {
    config()->set('crm.opportunity.owners', ['Ana', 'Luis']);

    $this->artisan('crm:configurar-pipeline', ['team' => $this->team->slug])->assertSuccessful();

    $owner = pipelineField(Opportunity::class, 'owner');
    $source = pipelineField(Opportunity::class, 'source');
    $probability = pipelineField(Opportunity::class, 'probability');
    $cif = pipelineField(Company::class, 'cif');
    $sector = pipelineField(Company::class, 'sector');

    expect($owner->type)->toBe('select')
        ->and(pipelineOptionNames($owner))->toBe(['Ana', 'Luis'])
        ->and($source->type)->toBe('select')
        ->and(pipelineOptionNames($source))->toBe(config('crm.opportunity.sources'))
        ->and($probability->type)->toBe('number')
        ->and($cif->type)->toBe('text')
        ->and($sector->type)->toBe('select')
        ->and(pipelineOptionNames($sector))->toBe(config('crm.company.sectors'));
});

it('adds new options to an existing select without removing the ones added from the panel', function (): void {
    $this->artisan('crm:configurar-pipeline', ['team' => $this->team->slug])->assertSuccessful();

    $source = pipelineField(Opportunity::class, 'source');
    new CustomFieldOption()->forceFill([
        'tenant_id' => $this->team->getKey(),
        'custom_field_id' => $source->getKey(),
        'name' => 'Feria',
        'sort_order' => 99,
    ])->save();

    config()->set('crm.opportunity.sources', [...config('crm.opportunity.sources'), 'Evento']);

    $this->artisan('crm:configurar-pipeline', ['team' => $this->team->slug])->assertSuccessful();

    expect(pipelineOptionNames($source))->toContain('Feria', 'Evento', 'Web');
});

it('changes nothing when run a second time', function (): void {
    $snapshot = fn (): array => CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->getKey())
        ->with(['options' => fn ($query) => $query->withoutGlobalScopes()])
        ->orderBy('entity_type')
        ->orderBy('code')
        ->get()
        ->map(fn (CustomField $field): array => [$field->code, $field->name, $field->options->pluck('name', 'id')->all()])
        ->all();

    $this->artisan('crm:configurar-pipeline', ['team' => $this->team->slug])->assertSuccessful();
    $first = $snapshot();

    $this->artisan('crm:configurar-pipeline', ['team' => $this->team->slug])->assertSuccessful();

    expect($snapshot())->toBe($first);
});

it('fails for an unknown workspace', function (): void {
    $this->artisan('crm:configurar-pipeline', ['team' => 'no-existe'])
        ->expectsOutputToContain('no-existe')
        ->assertFailed();
});
