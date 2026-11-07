<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

enum ProposalOutcome: string
{
    case Applied = 'applied';
    case LeftOut = 'left_out';
    case Discarded = 'discarded';
    case Expired = 'expired';
    case Replaced = 'replaced';
    case NotSent = 'not_sent';

    public function icon(): string
    {
        return match ($this) {
            self::Applied => 'lucide-check',
            self::LeftOut, self::Discarded, self::NotSent => 'lucide-ban',
            self::Expired => 'lucide-clock',
            self::Replaced => 'lucide-refresh-cw',
        };
    }

    public function iconClass(): string
    {
        return match ($this) {
            self::Applied => 'text-gray-500 dark:text-gray-400',
            self::LeftOut, self::Discarded, self::Expired, self::Replaced, self::NotSent => 'text-gray-400 dark:text-gray-500',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Applied => __('Applied'),
            self::LeftOut => __('left out'),
            self::Discarded => __('discarded'),
            self::Expired => __('expired'),
            self::Replaced => __('replaced by a newer proposal'),
            self::NotSent => __('Not sent'),
        };
    }

    public function leadsText(): bool
    {
        return match ($this) {
            self::NotSent => true,
            self::Applied, self::LeftOut, self::Discarded, self::Expired, self::Replaced => false,
        };
    }

    public function canProposeAgain(): bool
    {
        return match ($this) {
            self::Discarded, self::Expired => true,
            self::Applied, self::LeftOut, self::Replaced, self::NotSent => false,
        };
    }

    public static function forStatus(PendingActionStatus $status): ?self
    {
        return match ($status) {
            PendingActionStatus::Pending => null,
            PendingActionStatus::Approved => self::Applied,
            PendingActionStatus::Rejected => self::Discarded,
            PendingActionStatus::Expired => self::Expired,
            PendingActionStatus::Superseded => self::Replaced,
        };
    }

    /**
     * Which outcome a wholly undone plan reads as when its steps ended
     * differently: a discard outranks an expiry, and an expiry a replacement.
     *
     * @return list<self>
     */
    public static function settleOrder(): array
    {
        return [self::Discarded, self::Expired, self::Replaced];
    }

    /**
     * What happened to each step of one plan. A rejected step beside an
     * approved one was left out of it (or, for a send, not sent). With nothing
     * approved the plan ended as one outcome, a discard outranking an expiry
     * or a replacement.
     *
     * @param  array<string, array{status: PendingActionStatus, entity: ?ProposalEntity}>  $steps  keyed by step id
     * @return array<string, ?self>
     */
    public static function forGroup(array $steps): array
    {
        $applied = array_filter($steps, fn (array $step): bool => $step['status'] === PendingActionStatus::Approved) !== [];
        $ended = array_values(array_filter(array_map(fn (array $step): ?self => self::forStatus($step['status']), $steps)));
        $settled = collect(self::settleOrder())->first(fn (self $outcome): bool => in_array($outcome, $ended, true));

        return array_map(function (array $step) use ($applied, $settled): ?self {
            $outcome = self::forStatus($step['status']);

            if (! $applied) {
                return $outcome instanceof ProposalOutcome ? $settled : null;
            }

            return $outcome === self::Discarded
                ? ($step['entity']?->declinedOutcome() ?? self::LeftOut)
                : $outcome;
        }, $steps);
    }

    /**
     * @return array{
     *     outcomes: array<string, array{icon: string, label: string, leads: bool, again: bool}>,
     *     head: array{one: string, other: string},
     *     changes: array{one: string, other: string},
     *     justNow: string,
     *     localOutcomes: array<string, string>,
     *     settleOrder: list<string>
     * }
     */
    public static function forClient(): array
    {
        return [
            'outcomes' => collect(self::cases())
                ->mapWithKeys(fn (self $outcome): array => [$outcome->value => [
                    'icon' => svg($outcome->icon(), "size-4 shrink-0 {$outcome->iconClass()}")->toHtml(),
                    'label' => $outcome->label(),
                    'leads' => $outcome->leadsText(),
                    'again' => $outcome->canProposeAgain(),
                ]])
                ->all(),
            'head' => [
                'one' => __('Applied :count change · :when', ['count' => ':count', 'when' => ':when']),
                'other' => __('Applied :count changes · :when', ['count' => ':count', 'when' => ':when']),
            ],
            'changes' => [
                'one' => __(':count change', ['count' => ':count']),
                'other' => __(':count changes', ['count' => ':count']),
            ],
            'justNow' => __('just now'),
            'localOutcomes' => [
                PendingActionStatus::Expired->value => self::Expired->value,
                PendingActionStatus::Superseded->value => self::Replaced->value,
            ],
            'settleOrder' => array_map(fn (self $outcome): string => $outcome->value, self::settleOrder()),
        ];
    }
}
