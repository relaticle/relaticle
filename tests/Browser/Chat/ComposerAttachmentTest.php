<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Relaticle\Chat\Actions\ImportAttachment;
use Relaticle\Chat\Actions\StoreChatAttachment;
use Relaticle\Chat\Http\Controllers\ChatController;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Tests\Helpers\ChatBrowser;

mutates(ChatController::class, ImportAttachment::class);

afterEach(function (): void {
    foreach (Import::query()->pluck('id') as $storeId) {
        ImportStore::load((string) $storeId)?->destroy();
    }
});

/**
 * The Amp bridge behind Pest's browser server never parses multipart bodies
 * into files (`LaravelHttpServer::files` is hardcoded to `[]`), so a real
 * `fetch()` upload from the page can never reach `UploadedFile`. The
 * attachment is stored directly through the action the upload endpoint itself
 * calls, and its id is handed to the live page exactly as
 * `chatAttachment.upload()` would leave it.
 */
it('attaches a large csv, stores the handoff and lands on the mapping step', function (): void {
    Storage::fake('local');

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'attach');

    $lines = ['Name,Email,Company'];
    for ($i = 1; $i <= 40; $i++) {
        $lines[] = "Person {$i},person{$i}@example.test,Company {$i}";
    }

    $attachment = resolve(StoreChatAttachment::class)->execute(
        $user,
        UploadedFile::fake()->createWithContent('contacts.csv', implode("\n", $lines)."\n"),
        $conversationId,
    )->meta();

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    $attachmentState = json_encode([
        'id' => $attachment['id'],
        'name' => $attachment['name'],
        'row_count' => $attachment['row_count'],
    ], JSON_THROW_ON_ERROR);

    $page->script(<<<JS
        (() => {
            const island = document.querySelector('[data-chat-context="conversation"] [data-chat-attachment]');
            const data = Alpine.\$data(island);
            data.attachment = {$attachmentState};
            data.publish();
        })();
    JS);

    $page->waitForText('40 rows')
        ->assertVisible('[data-chat-context="conversation"] [data-chat-attachment-chip]');

    $page->script(<<<'JS'
        (() => {
            const wrapper = document.querySelector('[data-chat-context="conversation"][x-data*="chatEditor"]');
            Alpine.$data(wrapper).setText('Import these');
            wrapper.closest('form').requestSubmit();
        })();
    JS);

    $page->waitForText("That's 40 rows.")
        ->assertVisible('[data-chat-context="conversation"] [data-user-attachment]')
        ->assertSee('Import as people');

    expect(DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->count())->toBe(2);

    // The side panel mirrors the open conversation, so the handoff links exist
    // twice on the page; the click must stay scoped to the main transcript.
    $page->script(<<<'JS'
        (() => {
            const root = document.querySelector('[data-chat-context="conversation"]');
            const link = Array.from(root.querySelectorAll('a')).find((a) => a.textContent.trim() === 'Import as people');
            link.click();
        })();
    JS);

    $page->waitForText('File column')
        ->assertPathContains('/people/import');

    $import = Import::query()->where('workspace_id', $workspace->getKey())->firstOrFail();

    expect($import->total_rows)->toBe(40);
    $page->assertSee('Name')->assertSee('Email');
});
