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
            self::Applied => 'check',
            self::LeftOut, self::Discarded, self::NotSent => 'no-symbol',
            self::Expired => 'clock',
            self::Replaced => 'arrow-path',
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
     * @return array{
     *     outcomes: array<string, array{icon: string, label: string, leads: bool, again: bool}>,
     *     byStatus: array<string, string>,
     *     head: array{one: string, other: string},
     *     changes: array{one: string, other: string}
     * }
     */
    public static function forClient(): array
    {
        $byStatus = [];

        foreach (PendingActionStatus::cases() as $status) {
            $outcome = self::forStatus($status);

            if ($outcome instanceof self) {
                $byStatus[$status->value] = $outcome->value;
            }
        }

        return [
            'outcomes' => collect(self::cases())
                ->mapWithKeys(fn (self $outcome): array => [$outcome->value => [
                    'icon' => svg("heroicon-o-{$outcome->icon()}", "size-4 shrink-0 {$outcome->iconClass()}")->toHtml(),
                    'label' => $outcome->label(),
                    'leads' => $outcome->leadsText(),
                    'again' => $outcome->canProposeAgain(),
                ]])
                ->all(),
            'byStatus' => $byStatus,
            'head' => [
                'one' => __('Applied :count change · just now', ['count' => ':count']),
                'other' => __('Applied :count changes · just now', ['count' => ':count']),
            ],
            'changes' => [
                'one' => __(':count change', ['count' => ':count']),
                'other' => __(':count changes', ['count' => ':count']),
            ],
        ];
    }
}
