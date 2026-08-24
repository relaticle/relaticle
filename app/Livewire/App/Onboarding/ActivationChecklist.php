<?php

declare(strict_types=1);

namespace App\Livewire\App\Onboarding;

use App\Actions\Onboarding\DismissActivationChecklist;
use App\Data\ActivationStepData;
use App\Enums\CreationSource;
use App\Filament\Pages\ChatConversation;
use App\Filament\Pages\Team\Members;
use App\Filament\Resources\PeopleResource;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Relaticle\ImportWizard\Filament\Pages\ImportPeople;

/**
 * First-run guide on the dashboard. Each step is a fact about the workspace, not a
 * stored flag, so progress stays truthful however the record was created (form,
 * import, API, chat).
 *
 * @property-read list<ActivationStepData> $steps
 * @property-read int $completedCount
 */
final class ActivationChecklist extends Component
{
    /**
     * The record types a workspace can hold. Mirrors what the importer writes, so
     * any of them ticks the import step.
     *
     * @var list<string>
     */
    private const array ENTITY_TABLES = ['companies', 'people', 'tasks', 'notes', 'opportunities'];

    /**
     * Request-scoped cache for {@see self::creationSources()}.
     *
     * @var list<string>|null
     */
    private ?array $creationSources = null;

    public function dismiss(): void
    {
        $team = $this->team();

        if (! $team instanceof Team) {
            return;
        }

        resolve(DismissActivationChecklist::class)->execute($this->user(), $team);

        unset($this->steps);
    }

    public function render(): View
    {
        return view('livewire.app.onboarding.activation-checklist');
    }

    /**
     * Hidden once the workspace is set up, so a working CRM is never topped by a
     * checklist of things already done.
     */
    #[Computed]
    public function visible(): bool
    {
        $team = $this->team();

        if (! $team instanceof Team) {
            return false;
        }

        if ($team->activation_checklist_dismissed_at !== null) {
            return false;
        }

        // Every step links somewhere only a workspace admin can act on, so showing
        // this to an editor would be a checklist of 403s.
        if (! $this->user()->can('update', $team)) {
            return false;
        }

        return $this->completedCount < count($this->steps);
    }

    /**
     * @return list<ActivationStepData>
     */
    #[Computed]
    public function steps(): array
    {
        $team = $this->team();

        if (! $team instanceof Team) {
            return [];
        }

        return [
            new ActivationStepData(
                key: 'first_record',
                label: __('filament/pages/dashboard.activation.steps.first_record.label'),
                description: __('filament/pages/dashboard.activation.steps.first_record.description'),
                url: PeopleResource::getUrl('index'),
                icon: 'heroicon-o-user-plus',
                complete: $this->hasOwnRecord($team),
            ),
            new ActivationStepData(
                key: 'import',
                label: __('filament/pages/dashboard.activation.steps.import.label'),
                description: __('filament/pages/dashboard.activation.steps.import.description'),
                url: ImportPeople::getUrl(),
                icon: 'heroicon-o-arrow-up-tray',
                complete: $this->hasImportedRecord($team),
            ),
            new ActivationStepData(
                key: 'invite',
                label: __('filament/pages/dashboard.activation.steps.invite.label'),
                description: __('filament/pages/dashboard.activation.steps.invite.description'),
                url: Members::getUrl(),
                icon: 'heroicon-o-user-group',
                complete: $this->hasTeammate($team),
            ),
            new ActivationStepData(
                key: 'ask_rela',
                label: __('filament/pages/dashboard.activation.steps.ask_rela.label'),
                description: __('filament/pages/dashboard.activation.steps.ask_rela.description'),
                url: ChatConversation::getUrl(),
                icon: 'heroicon-o-sparkles',
                complete: $this->hasConversation($team),
            ),
        ];
    }

    #[Computed]
    public function completedCount(): int
    {
        return count(array_filter(
            $this->steps,
            fn (ActivationStepData $step): bool => $step->complete,
        ));
    }

    /**
     * Whether the workspace still holds the demo records seeded at sign-up. Drives
     * the "this is sample data" line, which would be a lie in a second workspace.
     */
    #[Computed]
    public function hasSampleData(): bool
    {
        $team = $this->team();

        if (! $team instanceof Team) {
            return false;
        }

        return in_array(CreationSource::SYSTEM->value, $this->creationSources($team), true);
    }

    private function hasOwnRecord(Team $team): bool
    {
        return array_any(
            $this->creationSources($team),
            fn (string $source): bool => $source !== CreationSource::SYSTEM->value,
        );
    }

    private function hasImportedRecord(Team $team): bool
    {
        return in_array(CreationSource::IMPORT->value, $this->creationSources($team), true);
    }

    private function hasTeammate(Team $team): bool
    {
        return $team->users()->exists() || $team->teamInvitations()->exists();
    }

    private function hasConversation(Team $team): bool
    {
        return DB::table('agent_conversations')
            ->where('team_id', $team->getKey())
            ->exists();
    }

    /**
     * Every distinct creation source present in the workspace, in one round trip.
     * Three of the four steps are answered from this list, so asking per step would
     * mean fifteen queries on a page that renders before the user has any data.
     *
     * @return list<string>
     */
    private function creationSources(Team $team): array
    {
        if ($this->creationSources !== null) {
            return $this->creationSources;
        }

        $query = null;

        foreach (self::ENTITY_TABLES as $table) {
            $branch = DB::table($table)
                ->select('creation_source')
                ->where('team_id', $team->getKey())
                ->whereNull('deleted_at')
                ->distinct();

            $query = $query instanceof QueryBuilder ? $query->union($branch) : $branch;
        }

        /** @var QueryBuilder $query */
        $sources = array_map(
            fn (mixed $source): string => (string) $source,
            $query->pluck('creation_source')->all(),
        );

        return $this->creationSources = array_values(array_unique($sources));
    }

    private function team(): ?Team
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Team ? $tenant : null;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
