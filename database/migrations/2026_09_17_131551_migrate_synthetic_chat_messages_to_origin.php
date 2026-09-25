<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Literal copies of MessageOrigin::opener(): a migration must not depend on app code.
    private const string GREETING_OPENER = 'The user opened their setup conversation.';

    private const string RESUME_OPENER = 'The user decided the proposals above.';

    private const string MESSAGES = 'agent_conversation_messages';

    // Released chat stored this prompt unmarked when a resume turn failed before its reply.
    private const string LEGACY_RESUME_PROMPT = 'The proposals from your last turn have just been decided. Their outcome is in <resolved_actions>. Confirm what happened in one short sentence, naming each record as a link. If a step of the request is still outstanding and you can act on it now, do it in this turn. If nothing is left, say so and stop.';

    // The three payloads the retired approval writer produced. A bare `[approval]`
    // prefix is not enough: a person can type that, and this rewrite is one-way.
    private const array LEGACY_APPROVAL_PREFIXES = [
        "[approval]\nstatus: ",
        "[approval]\nThe user APPROVED ",
        "[approval]\nThe user REJECTED the proposal to ",
    ];

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        DB::table(self::MESSAGES)
            ->where('role', 'user')
            ->where(function (Builder $legacy): void {
                foreach (self::LEGACY_APPROVAL_PREFIXES as $prefix) {
                    $legacy->orWhere('content', 'like', $prefix.'%');
                }
            })
            ->update(['origin' => 'resume']);

        $this->legacyContinuations()
            ->whereExists(function (Builder $conversation): void {
                $conversation->from('agent_conversations')
                    ->whereColumn('agent_conversations.id', self::MESSAGES.'.conversation_id')
                    ->where('agent_conversations.purpose', 'setup');
            })
            ->whereNotExists(function (Builder $earlier): void {
                $earlier->from(self::MESSAGES.' as earlier')
                    ->whereColumn('earlier.conversation_id', self::MESSAGES.'.conversation_id')
                    ->whereColumn('earlier.id', '<', self::MESSAGES.'.id');
            })
            ->update(['origin' => 'greeting']);

        $this->legacyContinuations()
            ->where('origin', 'typed')
            ->update(['origin' => 'resume']);

        DB::table(self::MESSAGES)
            ->where('role', 'user')
            ->where('origin', 'typed')
            ->where('content', self::LEGACY_RESUME_PROMPT)
            ->update(['origin' => 'resume']);

        DB::table(self::MESSAGES)->where('origin', 'greeting')->update(['content' => self::GREETING_OPENER]);
        DB::table(self::MESSAGES)->where('origin', 'resume')->update(['content' => self::RESUME_OPENER]);

        $this->legacyContinuations()->update(['meta' => DB::raw("meta - 'kind'")]);
    }

    private function legacyContinuations(): Builder
    {
        return DB::table(self::MESSAGES)
            ->where('role', 'user')
            ->whereRaw("meta->>'kind' = 'continuation'");
    }
};
