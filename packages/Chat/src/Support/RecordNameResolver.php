<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Relaticle\Chat\Models\PendingAction;

/**
 * Turns the record ids a proposal carries into the names its card shows.
 *
 * Every write tool needs this and each one used to keep a private copy. It also
 * has to understand plan references: `$ref:<pending_action_id>` names a record an
 * earlier step will create, so there is nothing in the database to look up. A
 * reference resolves to the proposed name plus the step it comes from — without
 * that, the row would resolve to an empty string and be dropped, and the user
 * would approve a link they were never shown.
 */
final readonly class RecordNameResolver
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public function name(mixed $id, string $modelClass, ?Team $team, string $nameAttribute = 'name'): string
    {
        if (PlanReference::is($id)) {
            return $this->pendingName($id);
        }

        if (! is_string($id) || $id === '') {
            return '';
        }

        $query = $modelClass::query()->whereKey($id);

        if ($team instanceof Team) {
            $query->where('team_id', $team->getKey());
        }

        return (string) ($query->value($nameAttribute) ?? '');
    }

    /**
     * @param  array<array-key, mixed>|null  $ids
     * @param  class-string<Model>  $modelClass
     */
    public function names(?array $ids, string $modelClass, ?Team $team, string $nameAttribute = 'name'): string
    {
        if ($ids === null || $ids === []) {
            return '';
        }

        $names = [];

        foreach ($ids as $id) {
            $name = $this->name($id, $modelClass, $team, $nameAttribute);

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return implode(', ', $names);
    }

    /**
     * The proposed name of a record an earlier step of this turn will create,
     * labelled with that step's position so the card says where it comes from.
     */
    private function pendingName(mixed $reference): string
    {
        $target = PlanReference::target($reference);

        if ($target === null) {
            return '';
        }

        $action = PendingAction::query()->find($target);

        if (! $action instanceof PendingAction) {
            return '';
        }

        $titleKey = ProposalCoreFields::titleKey($action->entity_type);
        $title = $action->action_data[$titleKey] ?? null;

        if (! is_string($title) || $title === '') {
            return '';
        }

        $step = $this->stepNumber($action);

        return $step === null
            ? $title
            : __(':name (step :step)', ['name' => $title, 'step' => $step]);
    }

    /**
     * 1-based position of the referenced proposal within its turn.
     */
    private function stepNumber(PendingAction $action): ?int
    {
        if ($action->turn_id === null) {
            return null;
        }

        // Keys are ULIDs, so they sort in creation order: counting the turn's
        // proposals up to and including this one gives its step number.
        return PendingAction::query()
            ->where('turn_id', $action->turn_id)
            ->where('id', '<=', $action->getKey())
            ->count();
    }
}
