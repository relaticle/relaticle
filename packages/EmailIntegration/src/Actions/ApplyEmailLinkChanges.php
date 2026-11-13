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
    /**
     * Applies every pair or none, and says for each whether it changed anything.
     *
     * @param  list<array{email: Email, record: Company|People|Opportunity}>  $pairs
     * @return list<bool>
     */
    public function execute(User $user, array $pairs, LinkEmailToRecord|UnlinkEmailFromRecord $change): array
    {
        return DB::transaction(function () use ($user, $pairs, $change): array {
            $emails = collect($pairs)->map(fn (array $pair): Email => $pair['email']);
            $currentKeys = Email::query()
                ->withoutGlobalScopes()
                ->whereKey($emails->map(fn (Email $email): string => $email->getKey())->unique()->values()->all())
                ->whereNotNull('conversation_key')
                ->pluck('conversation_key', 'id');
            $byKey = $emails
                ->filter(fn (Email $email): bool => $currentKeys->has($email->getKey()))
                ->keyBy(fn (Email $email): string => (string) $currentKeys->get($email->getKey()))
                ->sortKeys(SORT_STRING);

            foreach ($byKey as $key => $email) {
                $email->takeConversationLock((string) $key);
            }

            return array_map(fn (array $pair): bool => (bool) $change->execute($user, $pair['email'], $pair['record']), $pairs);
        });
    }
}
