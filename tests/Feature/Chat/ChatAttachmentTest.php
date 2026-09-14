<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\Chat\Actions\ImportAttachment;
use Relaticle\Chat\Actions\StoreChatAttachment;
use Relaticle\Chat\Commands\PruneChatAttachmentsCommand;
use Relaticle\Chat\Http\Controllers\ChatAttachmentController;
use Relaticle\Chat\Support\ChatAttachment;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\ImportFileLoader;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(StoreChatAttachment::class, ImportAttachment::class, ChatAttachmentController::class, PruneChatAttachmentsCommand::class, ImportFileLoader::class, ChatAttachment::class);

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

it('stores a csv as a media row on the team and reports its header and row count', function (): void {
    $response = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(3)]);

    $response->assertOk()->assertJson([
        'name' => 'contacts.csv',
        'row_count' => 3,
        'header' => ['Name', 'Email', 'Company'],
    ]);

    $attachment = ChatAttachment::find($this->workspace, $this->user, $response->json('id'));

    expect($attachment)->toBeInstanceOf(ChatAttachment::class)
        ->and($attachment->media->collection_name)->toBe(Workspace::CHAT_ATTACHMENTS_MEDIA_COLLECTION)
        ->and($attachment->media->disk)->toBe('local')
        ->and($attachment->name())->toBe('contacts.csv')
        ->and($attachment->header())->toBe(['Name', 'Email', 'Company'])
        ->and($attachment->rowCount())->toBe(3)
        ->and($attachment->isConsumed())->toBeFalse()
        ->and($attachment->fileExists())->toBeTrue();
});

it('hides an attachment from other members of the same team', function (): void {
    $id = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(2)])->json('id');
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'editor']);

    expect(ChatAttachment::find($this->workspace, $member, $id))->toBeNull();
});

it('accepts a txt file that holds csv rows', function (): void {
    $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(2, 'contacts.txt')])
        ->assertOk()
        ->assertJsonPath('row_count', 2);
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
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $other->getKey(),
        'workspace_id' => $other->currentWorkspace->getKey(),
        'title' => 'private',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

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

    expect(ChatAttachment::find($this->workspace, $this->user, $id)?->importIdFor(ImportEntityType::People))->toBe($import->id);
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

    $imports = Import::query()->where('workspace_id', $this->workspace->getKey())->orderBy('created_at')->get();
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

it('prunes media rows and files older than 24 hours and keeps newer ones', function (): void {
    $oldId = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(1)])->json('id');
    $freshId = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(1)])->json('id');
    Media::query()->where('uuid', $oldId)->update(['created_at' => now()->subHours(25)]);
    Media::query()->where('uuid', $freshId)->update(['created_at' => now()->subHours(2)]);
    $old = ChatAttachment::find($this->workspace, $this->user, $oldId)?->media;
    $fresh = ChatAttachment::find($this->workspace, $this->user, $freshId)?->media;

    $this->artisan('chat:prune-attachments')->assertSuccessful();

    expect(Media::query()->where('uuid', $oldId)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($old->getPathRelativeToRoot()))->toBeFalse()
        ->and(Media::query()->where('uuid', $freshId)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($fresh->getPathRelativeToRoot()))->toBeTrue();
});
