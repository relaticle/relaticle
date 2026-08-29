<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;

final class ChatBrowser
{
    /**
     * Insert an agent_conversations row directly: the browser suites seed
     * conversations at the SQL layer so a page mounts with history already
     * present. Pass $id when the test builds URLs before seeding.
     */
    public static function seedConversation(User $user, int|string $teamId, string $title, ?string $id = null): string
    {
        $id ??= (string) Str::uuid7();

        DB::table('agent_conversations')->insert([
            'id' => $id,
            'participant_type' => 'user',
            'participant_id' => (string) $user->getKey(),
            'team_id' => $teamId,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Log in through the real form and land on the team dashboard; pass a
     * conversation id to continue into that chat.
     */
    public static function logIn(User $user, string $slug, ?string $conversationId = null): AwaitableWebpage
    {
        $page = test()->visit('/app/login')
            ->type('[id="form.email"]', $user->email)
            ->type('[id="form.password"]', 'password')
            ->click('button.fi-btn')
            ->assertPathIs("/app/{$slug}");

        return $conversationId === null
            ? $page
            : $page->navigate("/app/{$slug}/chats/{$conversationId}");
    }

    /**
     * JS that resolves the chat interface's Alpine component on the current page.
     *
     * Since the chat drawer rework the interface is rendered collapsed on the
     * dashboard, so `offsetParent` is null even though the component is mounted
     * and its data is live. Prefer a visible host when there is one — a page may
     * mount both the drawer and an inline interface — and otherwise fall back to
     * the mounted one rather than handing `undefined` to `Alpine.$data()`.
     */
    public static function resolveInterface(string $variable = 'data'): string
    {
        return <<<JS
            const hosts = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'));
            const host = hosts.find((el) => el.offsetParent !== null) ?? hosts[0];

            if (! host) {
                throw new Error('No chatInterface component is mounted on this page.');
            }

            const {$variable} = Alpine.\$data(host);
        JS;
    }
}
