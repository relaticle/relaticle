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
            'who' => self::who($latest, $viewer),
            'time' => self::time($latest->at, $viewer),
            'assistant' => config('chat.assistant_name'),
        ];

        $what = self::what($conflicts, $replacements);
        $consequence = trans_choice('Approving replaces that change.|Approving replaces those changes.', count($conflicts));

        return "{$what} {$consequence}";
    }

    /**
     * @param  non-empty-list<ProposalConflict>  $conflicts
     * @param  array{who: string, time: string, assistant: mixed}  $replacements
     */
    private static function what(array $conflicts, array $replacements): string
    {
        if (count($conflicts) === 1) {
            $conflict = $conflicts[0];
            $replacements['field'] = $conflict->field;

            return match (true) {
                str_ends_with($conflict->key, '_ids') => __(':who changed :field at :time, after :assistant proposed this.', $replacements),
                $conflict->value === null => __(':who cleared :field at :time, after :assistant proposed this.', $replacements),
                default => __(':who set :field to :value at :time, after :assistant proposed this.', [...$replacements, 'value' => $conflict->value]),
            };
        }

        $replacements['fields'] = Arr::join(array_map(static fn (ProposalConflict $conflict): string => $conflict->field, $conflicts), ', ', ' '.__('and').' ');
        $causers = array_unique(array_map(static fn (ProposalConflict $conflict): string => $conflict->causerId ?? $conflict->causerName, $conflicts));

        return count($causers) === 1
            ? __(':who changed :fields at :time, after :assistant proposed this.', $replacements)
            : __(':fields changed, last at :time, after :assistant proposed this.', $replacements);
    }

    private static function who(ProposalConflict $conflict, User $viewer): string
    {
        return $conflict->causerId !== null && $conflict->causerId === (string) $viewer->getKey()
            ? __('You')
            : $conflict->causerName;
    }

    private static function time(CarbonImmutable $at, User $viewer): string
    {
        $timezone = $viewer->effectiveTimezone();
        $local = $at->setTimezone($timezone);

        return $local->isSameDay(now($timezone))
            ? $local->isoFormat('HH:mm')
            : $local->isoFormat('MMM D, HH:mm');
    }
}
