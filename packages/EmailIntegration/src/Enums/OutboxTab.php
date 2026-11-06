<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Models\Email;

enum OutboxTab: string implements HasLabel
{
    case QUEUED = 'queued';
    case SCHEDULED = 'scheduled';
    case SENDING = 'sending';
    case FAILED = 'failed';
    case SENT = 'sent';

    public function getLabel(): string
    {
        return match ($this) {
            self::QUEUED => __('filament/pages/email-outbox.tabs.queued'),
            self::SCHEDULED => __('filament/pages/email-outbox.tabs.scheduled'),
            self::SENDING => __('filament/pages/email-outbox.tabs.sending'),
            self::FAILED => __('filament/pages/email-outbox.tabs.failed'),
            self::SENT => __('filament/pages/email-outbox.tabs.sent'),
        };
    }

    public function emptyStateHeading(): string
    {
        return match ($this) {
            self::QUEUED => __('filament/pages/email-outbox.empty.queued.heading'),
            self::SCHEDULED => __('filament/pages/email-outbox.empty.scheduled.heading'),
            self::SENDING => __('filament/pages/email-outbox.empty.sending.heading'),
            self::FAILED => __('filament/pages/email-inbox.failed.empty.heading'),
            self::SENT => __('filament/pages/email-outbox.empty.sent.heading'),
        };
    }

    public function emptyStateDescription(): string
    {
        return match ($this) {
            self::QUEUED => __('filament/pages/email-outbox.empty.queued.description'),
            self::SCHEDULED => __('filament/pages/email-outbox.empty.scheduled.description'),
            self::SENDING => __('filament/pages/email-outbox.empty.sending.description'),
            self::FAILED => __('filament/pages/email-inbox.failed.empty.description'),
            self::SENT => __('filament/pages/email-outbox.empty.sent.description'),
        };
    }

    /**
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    public function constrain(Builder $query): Builder
    {
        // An undo window and an assistant's hold are not scheduled sends, so the queued
        // segment shows both at once, matching the outbox badge.
        $dueCutoff = now()->addSeconds(Config::integer('email-integration.outbox.undo_send_window_seconds'));

        return match ($this) {
            self::SCHEDULED => $query->where('status', EmailStatus::QUEUED)
                ->whereNotNull('scheduled_for')->where('scheduled_for', '>', $dueCutoff)
                ->whereNot(fn (Builder $held): Builder => $held->createdOverMcp()),
            self::QUEUED => $query->where('status', EmailStatus::QUEUED)
                ->where(fn (Builder $due): Builder => $due->whereNull('scheduled_for')
                    ->orWhere('scheduled_for', '<=', $dueCutoff)
                    ->orWhere(fn (Builder $held): Builder => $held->createdOverMcp())),
            self::SENDING => $query->where('status', EmailStatus::SENDING),
            self::FAILED => $query->where('status', EmailStatus::FAILED),
            self::SENT => $query->where('status', EmailStatus::SENT)->where('sent_at', '>=', now()->subDay()),
        };
    }
}
