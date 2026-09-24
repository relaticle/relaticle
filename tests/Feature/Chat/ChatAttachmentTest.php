<?php

declare(strict_types=1);

use App\Actions\Jetstream\DeleteWorkspace;
use App\Enums\MediaCollection;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\Chat\Actions\CreateConversation;
use Relaticle\Chat\Actions\DeleteChatAttachment;
use Relaticle\Chat\Actions\DeleteConversation;
use Relaticle\Chat\Actions\ImportAttachment;
use Relaticle\Chat\Actions\MarkAttachmentSent;
use Relaticle\Chat\Actions\StoreChatAttachment;
use Relaticle\Chat\Commands\PurgeUnsentAttachmentsCommand;
use Relaticle\Chat\Http\Controllers\ChatAttachmentController;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Support\ChatAttachment;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\ImportFileLoader;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(StoreChatAttachment::class, CreateConversation::class, DeleteChatAttachment::class, DeleteConversation::class, ImportAttachment::class, ChatAttachmentController::class, PurgeUnsentAttachmentsCommand::class, ImportFileLoader::class, ChatAttachment::class);

beforeEach(function (): void {
    Storage::fake('local');

    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    $this->createdStoreIds = [];
});

afterEach(function (): void {
    foreach ($this->createdStoreIds as $storeId) {
        ImportStore::load($storeId)?->destroy();
    }
});

function csvUpload(int $rows, string $name = 'contacts.csv'): UploadedFile
{
    $lines = ['Name,Email,Company'];

    for ($i = 1; $i <= $rows; $i++) {
        $lines[] = "Person {$i},person{$i}@example.test,Company {$i}";
    }

    return UploadedFile::fake()->createWithContent($name, implode("\n", $lines)."\n");
}

function attachmentConversation(User $user, string $title = 'setup'): string
{
    $id = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $user->current_workspace_id,
        'title' => $title,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('opens a conversation titled after the file and stores the csv on it', function (): void {
    $response = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(3)]);

    $response->assertOk()->assertJson([
        'name' => 'contacts.csv',
        'row_count' => 3,
        'header' => ['Name', 'Email', 'Company'],
    ]);

    $conversation = AgentConversation::query()->findOrFail($response->json('conversation_id'));
    $attachment = ChatAttachment::find($this->user, $response->json('id'));

    expect($conversation->title)->toBe('contacts.csv')
        ->and($conversation->participant_id)->toBe((string) $this->user->getKey())
        ->and($conversation->workspace_id)->toBe($this->workspace->getKey())
        ->and($conversation->attachments()->count())->toBe(1)
        ->and($attachment)->toBeInstanceOf(ChatAttachment::class)
        ->and($attachment->media->model_type)->toBe('agent_conversation')
        ->and($attachment->media->model_id)->toBe($conversation->getKey())
        ->and($attachment->media->collection_name)->toBe(MediaCollection::ChatAttachments->value)
        ->and($attachment->media->workspace_id)->toBe($this->workspace->getKey())
        ->and($attachment->media->disk)->toBe('local')
        ->and($attachment->name())->toBe('contacts.csv')
        ->and($attachment->header())->toBe(['Name', 'Email', 'Company'])
        ->and($attachment->rowCount())->toBe(3)
        ->and($attachment->conversationId())->toBe($conversation->getKey())
        ->and($attachment->isSent())->toBeFalse()
        ->and($attachment->fileExists())->toBeTrue();
});

it('stores the csv on the conversation it was uploaded in', function (): void {
    $conversationId = attachmentConversation($this->user);

    $response = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(2), 'conversation_id' => $conversationId])
        ->assertOk()
        ->assertJsonPath('conversation_id', $conversationId);

    expect(ChatAttachment::find($this->user, $response->json('id'))?->media->model_id)->toBe($conversationId)
        ->and(AgentConversation::query()->count())->toBe(1);
});

it('deletes the attachment and its file with the conversation', function (): void {
    $response = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(2)])->assertOk();
    $media = ChatAttachment::find($this->user, $response->json('id'))->media;

    $this->deleteJson(route('chat.conversations.destroy', $response->json('conversation_id')))->assertOk();

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($media->getPathRelativeToRoot()))->toBeFalse()
        ->and(AgentConversation::query()->count())->toBe(0);
});

it('removes an unsent attachment together with the conversation the upload opened', function (): void {
    $response = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(2)])->assertOk();
    $media = ChatAttachment::find($this->user, $response->json('id'))->media;

    $this->deleteJson(route('chat.attachments.destroy', ['attachment' => $response->json('id')]))->assertOk();

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($media->getPathRelativeToRoot()))->toBeFalse()
        ->and(AgentConversation::query()->whereKey($response->json('conversation_id'))->exists())->toBeFalse();
});

it('removes an unsent attachment but keeps a conversation that already has messages', function (): void {
    $conversationId = attachmentConversation($this->user);
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'agent' => 'crm-assistant',
        'role' => 'user',
        'content' => 'hello',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $id = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(2), 'conversation_id' => $conversationId])->json('id');

    $this->deleteJson(route('chat.attachments.destroy', ['attachment' => $id]))->assertOk();

    expect(ChatAttachment::find($this->user, $id))->toBeNull()
        ->and(AgentConversation::query()->whereKey($conversationId)->exists())->toBeTrue();
});

it('refuses to remove an attachment that was already sent', function (): void {
    $id = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(2)])->json('id');
    resolve(MarkAttachmentSent::class)->execute(ChatAttachment::find($this->user, $id));

    $this->deleteJson(route('chat.attachments.destroy', ['attachment' => $id]))->assertNotFound();

    expect(ChatAttachment::find($this->user, $id)?->fileExists())->toBeTrue();
});

it('sweeps attachments with the workspace that owns them', function (): void {
    $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(2)])->assertOk();

    $media = Media::query()
        ->where('collection_name', MediaCollection::ChatAttachments->value)
        ->sole();

    resolve(DeleteWorkspace::class)->delete($this->workspace);

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($media->getPathRelativeToRoot()))->toBeFalse();
});

it('hides an attachment from other members of the same team', function (): void {
    $id = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(2)])->json('id');
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'member']);
    $member->switchWorkspace($this->workspace);

    expect(ChatAttachment::find($member, $id))->toBeNull();
});

it('accepts a txt file that holds csv rows', function (): void {
    $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(2, 'contacts.txt')])
        ->assertOk()
        ->assertJsonPath('row_count', 2);
});

it('caps a filename longer than the media name column', function (): void {
    $longName = str_repeat('a', 296).'.csv';
    $cappedName = Str::limit($longName, 255, '');

    $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(2, $longName)])
        ->assertOk()
        ->assertJsonPath('name', $cappedName);
});

it('rejects other file types and files over 10 MB', function (): void {
    $this->postJson(route('chat.attachments.store'), ['file' => UploadedFile::fake()->create('book.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);

    $this->postJson(route('chat.attachments.store'), ['file' => UploadedFile::fake()->create('big.csv', 10241, 'text/csv')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

it('rejects a file with no header row, no data rows, or duplicate columns with the wizard messages', function (string $content, string $message): void {
    $this->postJson(route('chat.attachments.store'), ['file' => UploadedFile::fake()->createWithContent('contacts.csv', $content)])
        ->assertStatus(422)
        ->assertJsonPath('errors.file.0', $message);

    expect(AgentConversation::query()->count())->toBe(0);
})->with([
    'empty' => ['', 'CSV file is empty'],
    'header only' => ["Name,Email\n", 'CSV file has no data rows'],
    'duplicate columns' => ["Name,Name\nJane,Doe\n", 'Duplicate column names found.'],
]);

it('rejects a file that is not utf-8', function (): void {
    $content = "Name,Email\n".mb_convert_encoding('Zoë,zoe@example.test', 'ISO-8859-1', 'UTF-8')."\n";

    $this->postJson(route('chat.attachments.store'), ['file' => UploadedFile::fake()->createWithContent('contacts.csv', $content)])
        ->assertStatus(422)
        ->assertJsonPath('errors.file.0', 'The file must be UTF-8 text.');
});

it('rejects a conversation that belongs to someone else', function (): void {
    $other = User::factory()->withPersonalWorkspace()->create();
    $conversationId = attachmentConversation($other, 'private');

    $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(2), 'conversation_id' => $conversationId])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['conversation_id']);
});

it('builds a people import from the stored file and lands on the mapping step', function (): void {
    $id = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(40)])->json('id');

    $response = $this->get(route('chat.attachments.import', ['attachment' => $id, 'entity' => 'people']));

    $import = Import::query()->where('workspace_id', $this->workspace->getKey())->firstOrFail();
    $this->createdStoreIds[] = $import->id;

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/people/import')
        ->toContain('import='.$import->id);

    expect($import->entity_type)->toBe(ImportEntityType::People)
        ->and($import->status)->toBe(ImportStatus::Mapping)
        ->and($import->total_rows)->toBe(40)
        ->and($import->headers)->toBe(['Name', 'Email', 'Company'])
        ->and($import->file_name)->toBe('contacts.csv')
        ->and(ImportStore::load($import->id)?->query()->count())->toBe(40);

    expect(ChatAttachment::find($this->user, $id)?->importIdFor(ImportEntityType::People))->toBe($import->id);
});

it('redirects a repeated click to the import already built', function (): void {
    $id = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(30)])->json('id');

    $this->get(route('chat.attachments.import', ['attachment' => $id, 'entity' => 'people']));
    $this->get(route('chat.attachments.import', ['attachment' => $id, 'entity' => 'people']));

    $imports = Import::query()->where('workspace_id', $this->workspace->getKey())->get();
    $this->createdStoreIds = $imports->pluck('id')->all();

    expect($imports)->toHaveCount(1);
});

it('builds a second import for companies from the same kept file', function (): void {
    $id = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(30)])->json('id');

    $this->get(route('chat.attachments.import', ['attachment' => $id, 'entity' => 'people']));
    $response = $this->get(route('chat.attachments.import', ['attachment' => $id, 'entity' => 'company']));

    $imports = Import::query()->where('workspace_id', $this->workspace->getKey())->orderBy('id')->get();
    $this->createdStoreIds = $imports->pluck('id')->all();

    expect($imports)->toHaveCount(2)
        ->and($imports->last()->entity_type)->toBe(ImportEntityType::Company);
    expect($response->headers->get('Location'))->toContain('/companies/import');
});

it('refuses an unsupported entity and a foreign attachment', function (): void {
    $id = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(30)])->json('id');

    $this->get(route('chat.attachments.import', ['attachment' => $id, 'entity' => 'task']))->assertNotFound();

    $other = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($other);

    $this->get(route('chat.attachments.import', ['attachment' => $id, 'entity' => 'people']))->assertNotFound();
});

it('purges unsent attachments older than a day with the conversation they opened, and keeps sent and fresh ones', function (): void {
    $staleUnsent = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(1)])->json();
    $secondStaleUnsent = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(1)])->json();
    $staleSent = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(1)])->json();
    $freshUnsent = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(1)])->json();
    resolve(MarkAttachmentSent::class)->execute(ChatAttachment::find($this->user, $staleSent['id']));
    Media::query()->whereIn('uuid', [$staleUnsent['id'], $secondStaleUnsent['id'], $staleSent['id']])->update(['created_at' => now()->subHours(25)]);
    $staleUnsentPath = ChatAttachment::find($this->user, $staleUnsent['id'])->media->getPathRelativeToRoot();

    $this->artisan('chat:purge-unsent-attachments')
        ->expectsOutputToContain('Purged 2 attachment(s).')
        ->assertSuccessful();

    expect(Media::query()->whereIn('uuid', [$staleUnsent['id'], $secondStaleUnsent['id']])->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($staleUnsentPath))->toBeFalse()
        ->and(AgentConversation::query()->whereKey($staleUnsent['conversation_id'])->exists())->toBeFalse()
        ->and(ChatAttachment::find($this->user, $staleSent['id'])?->fileExists())->toBeTrue()
        ->and(AgentConversation::query()->whereKey($staleSent['conversation_id'])->exists())->toBeTrue()
        ->and(ChatAttachment::find($this->user, $freshUnsent['id'])?->fileExists())->toBeTrue();
});
