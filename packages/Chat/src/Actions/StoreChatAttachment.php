<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Relaticle\Chat\Support\ChatAttachment;
use Relaticle\ImportWizard\Exceptions\ImportFileException;
use Relaticle\ImportWizard\Support\ImportFileLoader;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;

final readonly class StoreChatAttachment
{
    public const int MAX_KILOBYTES = 10240;

    public function __construct(
        private ImportFileLoader $loader,
        private FindConversation $conversations,
    ) {}

    public function execute(User $user, UploadedFile $file, ?string $conversationId = null): ChatAttachment
    {
        $workspace = $user->currentWorkspace;

        abort_if($workspace === null, 403);

        if ($conversationId !== null && ! $this->conversations->execute($user, $conversationId) instanceof \stdClass) {
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
            $media = $workspace->addMedia($file)
                ->usingFileName(Str::ulid().'.csv')
                ->usingName(pathinfo($originalName, PATHINFO_FILENAME))
                ->withCustomProperties([
                    'uploaded_by' => (string) $user->getKey(),
                    'original_name' => $originalName,
                    'conversation_id' => $conversationId,
                    'row_count' => $inspection['row_count'],
                    'header' => $inspection['headers'],
                ])
                ->toMediaCollection(Workspace::CHAT_ATTACHMENTS_MEDIA_COLLECTION);
        } catch (FileUnacceptableForCollection) {
            throw ValidationException::withMessages(['file' => __('The file must be a CSV or plain text file.')]);
        }

        return new ChatAttachment($media);
    }
}
