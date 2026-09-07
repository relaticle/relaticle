<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\EmailIntegration\Models\Email;

final readonly class PreferredEmailCopyService
{
    /**
     * Keep one visible copy of each RFC Message-ID.
     *
     * Two connected mailboxes can store the same message. The record mailbox
     * should show it once. Prefer the viewer's own copy when they have one.
     *
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    public function restrictToPreferredCopies(Builder $query, User $viewer): Builder
    {
        $preferred = $query->clone();
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
            ->latest('emails.sent_at')
            ->orderByDesc('emails.id');

        return $query->whereIn($query->qualifyColumn('id'), $preferred);
    }

    /**
     * True when this message already exists in the viewer's synced mailbox.
     */
    public function viewerHasSyncedCopy(Email $email, User $viewer): bool
    {
        if ($email->user_id === $viewer->getKey()) {
            return true;
        }

        if (blank($email->rfc_message_id)) {
            return false;
        }

        return Email::query()
            ->where('team_id', $email->team_id)
            ->where('user_id', $viewer->getKey())
            ->where('rfc_message_id', $email->rfc_message_id)
            ->exists();
    }
}
