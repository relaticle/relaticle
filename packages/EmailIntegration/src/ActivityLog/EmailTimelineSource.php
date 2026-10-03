<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\ActivityLog;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Relaticle\ActivityLog\Timeline\Sources\RelatedModelSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;

final readonly class EmailTimelineSource
{
    public function __construct(private ?Authenticatable $viewer) {}

    public function __invoke(RelatedModelSource $source): void
    {
        $source
            ->event(
                'sent_at',
                'email_sent',
                when: fn (Email $email): bool => $email->direction === EmailDirection::OUTBOUND,
            )
            ->event(
                'sent_at',
                'email_received',
                when: fn (Email $email): bool => $email->direction === EmailDirection::INBOUND,
            )
            ->event(
                'created_at',
                'email_received',
                when: fn (Email $email): bool => $email->direction === EmailDirection::INBOUND && $email->sent_at === null,
            )
            ->with(['from', 'labels', 'participants', 'shares'])
            ->title(function (Email $email): string {
                // VisibleEmailScope admits metadata-only mail. Subjects are masked
                // here the same way the inbox does: viewSubject, not a raw column read.
                if (! $this->viewer instanceof User || ! $this->viewer->can('viewSubject', $email)) {
                    return __('filament/pages/email-inbox.subject.hidden');
                }

                return $email->subject ?? 'Email';
            })
            ->description(fn (Email $email): ?string => $email->from->first()?->email_address)
            ->causer(fn (Email $email) => $email->from->first());

        if ($this->viewer instanceof User) {
            $source->using(fn (Builder|Relation $query) => $query->withGlobalScope(
                'visible',
                new VisibleEmailScope($this->viewer),
            ));
        } else {
            // No authenticated viewer, so never expose email content unscoped.
            // VisibleEmailScope isn't a default scope on Email, so without this
            // the relation would load every email regardless of privacy.
            $source->using(fn (Builder|Relation $query) => $query->whereRaw('1 = 0'));
        }
    }
}
