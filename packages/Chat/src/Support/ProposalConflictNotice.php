<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Relaticle\Chat\Data\ProposalConflict;

final readonly class ProposalConflictNotice
{
    /**
     * "Maya Chen set Amount to $20,000 at 14:32, after Rela proposed this. Approving
     * replaces that change." Who, what and when, so the user decides knowingly.
     *
     * @param  non-empty-list<ProposalConflict>  $conflicts
     */
    public static function for(array $conflicts, User $viewer): string
    {
        $latest = array_reduce(
            $conflicts,
            static fn (?ProposalConflict $carry, ProposalConflict $conflict): ProposalConflict => $carry instanceof ProposalConflict && $carry->at->gt($conflict->at) ? $carry : $conflict,
        );

        $replacements = [
            'who' => $latest->causerName,
            'when' => self::when($latest->at, $viewer),
            'assistant' => config('chat.assistant_name'),
        ];

        $byViewer = self::byViewer($latest, $viewer);
        $what = self::what($conflicts, $replacements, $byViewer);
        $consequence = trans_choice('Approving replaces that change.|Approving replaces those changes.', count($conflicts));

        return "{$what} {$consequence}";
    }

    /**
     * @param  non-empty-list<ProposalConflict>  $conflicts
     * @param  array{who: string, when: string, assistant: mixed}  $replacements
     */
    private static function what(array $conflicts, array $replacements, bool $byViewer): string
    {
        if (count($conflicts) === 1) {
            $conflict = $conflicts[0];
            $replacements['field'] = $conflict->field;

            return match (true) {
                str_ends_with($conflict->key, '_ids') => $byViewer
                    ? __('You changed :field :when, after :assistant proposed this.', $replacements)
                    : __(':who changed :field :when, after :assistant proposed this.', $replacements),
                $conflict->value === null => $byViewer
                    ? __('You cleared :field :when, after :assistant proposed this.', $replacements)
                    : __(':who cleared :field :when, after :assistant proposed this.', $replacements),
                default => $byViewer
                    ? __('You set :field to :value :when, after :assistant proposed this.', [...$replacements, 'value' => $conflict->value])
                    : __(':who set :field to :value :when, after :assistant proposed this.', [...$replacements, 'value' => $conflict->value]),
            };
        }

        $replacements['fields'] = Arr::join(array_map(static fn (ProposalConflict $conflict): string => $conflict->field, $conflicts), ', ', ' '.__('and').' ');
        $causers = array_unique(array_map(static fn (ProposalConflict $conflict): string => $conflict->causerId ?? $conflict->causerName, $conflicts));

        return match (true) {
            count($causers) !== 1 => __(':fields changed, last :when, after :assistant proposed this.', $replacements),
            $byViewer => __('You changed :fields :when, after :assistant proposed this.', $replacements),
            default => __(':who changed :fields :when, after :assistant proposed this.', $replacements),
        };
    }

    private static function byViewer(ProposalConflict $conflict, User $viewer): bool
    {
        return $conflict->causerId !== null && $conflict->causerId === (string) $viewer->getKey();
    }

    private static function when(CarbonImmutable $at, User $viewer): string
    {
        $local = $at->setTimezone($viewer->effectiveTimezone());

        return $local->isToday()
            ? __('at :time', ['time' => $local->isoFormat('LT')])
            : __('on :time', ['time' => $local->isoFormat('lll')]);
    }
}
