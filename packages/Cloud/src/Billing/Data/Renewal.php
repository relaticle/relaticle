<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Data;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Number;

final readonly class Renewal
{
    private const string CACHE_PREFIX = 'renewal:';

    public function __construct(
        public CarbonImmutable $renewsAt,
        public int $amount,
        public ?int $amountDue,
        public string $currency,
        public ?string $cardBrand,
        public ?string $cardLast4,
    ) {}

    public static function cacheKey(string $stripeSubscriptionId): string
    {
        return self::CACHE_PREFIX.$stripeSubscriptionId;
    }

    public static function remembered(string $stripeSubscriptionId): ?self
    {
        $renewal = Cache::get(self::cacheKey($stripeSubscriptionId));

        if (! is_array($renewal) || ! is_int($renewal['renews_at'] ?? null) || ! is_int($renewal['amount'] ?? null)) {
            return null;
        }

        return new self(
            renewsAt: Date::createFromTimestamp($renewal['renews_at']),
            amount: $renewal['amount'],
            amountDue: is_int($renewal['amount_due'] ?? null) ? $renewal['amount_due'] : null,
            currency: is_string($renewal['currency'] ?? null) ? $renewal['currency'] : 'usd',
            cardBrand: is_string($renewal['card_brand'] ?? null) ? $renewal['card_brand'] : null,
            cardLast4: is_string($renewal['card_last4'] ?? null) ? $renewal['card_last4'] : null,
        );
    }

    public function amount(): string
    {
        return $this->money($this->amount);
    }

    public function amountDue(): ?string
    {
        return $this->amountDue === null ? null : $this->money($this->amountDue);
    }

    public function card(): ?string
    {
        if ($this->cardBrand === null || $this->cardLast4 === null) {
            return null;
        }

        $brand = match ($this->cardBrand) {
            'amex' => 'American Express',
            'diners' => 'Diners Club',
            'unionpay' => 'UnionPay',
            default => ucfirst($this->cardBrand),
        };

        return __('cloud::billing.summary.card', ['brand' => $brand, 'last4' => $this->cardLast4]);
    }

    private function money(int $cents): string
    {
        return (string) Number::currency($cents / 100, strtoupper($this->currency), precision: $cents % 100 === 0 ? 0 : 2);
    }
}
