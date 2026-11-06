<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Data\TriageOutcome;
use Relaticle\EmailIntegration\Enums\TriageSkipReason;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

final readonly class PrepareConversationTriage
{
    public const int MAX_KEYS = 100;

    public function __construct(private ConversationsQuery $conversations) {}

    /** @param  array<mixed>  $conversationKeys */
    public function execute(User $user, array $conversationKeys): TriageOutcome
    {
        abort_unless($user->can('viewAny', Email::class), 403);

        $keys = array_values(array_unique(array_filter($conversationKeys, is_string(...))));

        if (count($keys) > self::MAX_KEYS) {
            throw ValidationException::withMessages([
                'keys' => __('filament/pages/email-inbox.triage.too_many', ['max' => self::MAX_KEYS]),
            ]);
        }

        $visible = $this->conversations->visibleKeys($user, $keys);

        return new TriageOutcome($keys)->skipping(TriageSkipReason::NotAvailable, array_values(array_diff($keys, $visible)));
    }
}
