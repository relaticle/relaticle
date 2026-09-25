<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Support\ChatAttachment;
use Relaticle\ImportWizard\Exceptions\ImportFileException;
use Relaticle\ImportWizard\Support\ImportFileLoader;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

final readonly class StoreChatAttachment
{
    public const int MAX_KILOBYTES = 10240;

    public function __construct(
        private ImportFileLoader $loader,
        private CreateConversation $conversations,
    ) {}

    public function execute(User $user, UploadedFile $file, ?string $conversationId = null): ChatAttachment
    {
        $workspace = $user->currentWorkspace;

        abort_if($workspace === null, 403);

        $conversation = $conversationId === null
            ? null
            : AgentConversation::query()->ownedBy($user)->find($conversationId);

        if ($conversationId !== null && ! $conversation instanceof AgentConversation) {
            throw ValidationException::withMessages(['conversation_id' => __('That conversation is not yours.')]);
        }

        $path = (string) $file->getRealPath();

        if (! mb_check_encoding((string) file_get_contents($path), 'UTF-8')) {
            throw ValidationException::withMessages(['file' => __('The file must be UTF-8 text.')]);
        }

        try {
            $inspection = $this->loader->inspect($path);
        } catch (ImportFileException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $originalName = Str::limit($file->getClientOriginalName(), 255, '');

        try {
            $media = DB::transaction(function () use ($user, $workspace, $file, $conversation, $originalName, $inspection) {
                $conversation ??= $this->conversations->execute($user, $workspace, $originalName);

                return $conversation->addMedia($file)
                    ->usingFileName(Str::ulid().'.csv')
                    ->usingName(pathinfo($originalName, PATHINFO_FILENAME))
                    ->withAttributes(['workspace_id' => $workspace->getKey()])
                    ->withCustomProperties([
                        'original_name' => $originalName,
                        'row_count' => $inspection['row_count'],
                        'header' => $inspection['headers'],
                    ])
                    ->toMediaCollection(AgentConversation::ATTACHMENTS_MEDIA_COLLECTION);
            });
        } catch (FileUnacceptableForCollection) {
            throw ValidationException::withMessages(['file' => __('The file must be a CSV or plain text file.')]);
        }

        return new ChatAttachment($media);
    }
}
