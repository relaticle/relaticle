<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Models\Email;

final readonly class ApplyEmailLinkChanges
{
    /** @param  list<array{email: Email, record: Company|People|Opportunity}>  $pairs */
    public function execute(User $user, array $pairs, LinkEmailToRecord|UnlinkEmailFromRecord $change): void
    {
        DB::transaction(function () use ($user, $pairs, $change): void {
            foreach ($pairs as ['email' => $email, 'record' => $record]) {
                $change->execute($user, $email, $record);
            }
        });
    }
}
