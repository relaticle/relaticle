<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;

final readonly class PreferredEmailCopyService
{
    /**
     * Keep one copy the viewer may see of each RFC Message-ID.
     *
     * Two connected mailboxes can store the same message. The record mailbox
     * should show it once. Prefer the viewer's own copy, then the one most open
     * to the viewer, counting a share made with them on that copy.
     *
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    public function restrictToVisiblePreferredCopies(Builder $query, User $viewer): Builder
    {
        $preferred = $query->clone()->withGlobalScope('visible', new VisibleEmailScope($viewer));
        $base = $preferred->getQuery();
        $base->orders = null;
        $base->columns = null;
        $base->limit = null;
        $base->offset = null;
        $base->bindings['select'] = [];
        $base->bindings['order'] = [];

        $preferred
            ->selectRaw('distinct on (coalesce(emails.rfc_message_id, emails.id)) emails.id')
            ->orderByRaw('coalesce(emails.rfc_message_id, emails.id)')
            ->orderByRaw('case when emails.user_id = ? then 0 else 1 end', [$viewer->getKey()])
            ->orderByRaw(
                'array_position(?::text[], coalesce((select email_shares.tier from email_shares where email_shares.email_id = emails.id and email_shares.shared_with = ?), emails.privacy_tier::text)) desc nulls last',
                [EmailPrivacyTier::opennessRanking(), $viewer->getKey()],
            )
            ->latest('emails.sent_at')
            ->orderByDesc('emails.id');

        return $query->whereIn($query->qualifyColumn('id'), $preferred);
    }

    /**
     * Attach the connected mailboxes that hold a visible copy of each listed
     * message. The address is the mailbox, not the Relaticle login.
     *
     * @param  iterable<int, Email>  $emails
     */
    public function hydrateMailboxAccess(iterable $emails, User $viewer, Company|Opportunity|People $record): void
    {
        $items = collect($emails);

        if ($items->isEmpty()) {
            return;
        }

        $messageIds = $items
            ->pluck('rfc_message_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();

        $copies = $messageIds->isEmpty()
            ? collect()
            : $record->emails()
                ->with(['connectedAccount.user'])
                ->withGlobalScope('visible', new VisibleEmailScope($viewer))
                ->whereIn('rfc_message_id', $messageIds)
                ->get();

        $grouped = $copies->groupBy('rfc_message_id');

        foreach ($items as $email) {
            $siblings = filled($email->rfc_message_id)
                ? $grouped->get($email->rfc_message_id, collect())
                : collect();

            if ($siblings->isEmpty()) {
                $siblings = collect([$email]);
            }

            $email->accessMailboxes = $this->uniqueMailboxRows($siblings);
        }
    }

    /**
     * @param  Collection<int, Email>  $copies
     * @return list<array{name: string, mailbox_email: string}>
     */
    private function uniqueMailboxRows(Collection $copies): array
    {
        $mailboxes = [];
        $seen = [];

        foreach ($copies as $copy) {
            $account = $copy->connectedAccount;

            if ($account === null) {
                continue;
            }

            $mailboxEmail = $account->email_address;

            if ($mailboxEmail === '') {
                continue;
            }

            $key = Str::lower($mailboxEmail);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $mailboxes[] = [
                'name' => $account->user?->name ?: ($account->display_name ?: $mailboxEmail),
                'mailbox_email' => $mailboxEmail,
            ];
        }

        return $mailboxes;
    }
}
