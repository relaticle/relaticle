<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Support\AttachedText;
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

    public static function originalName(UploadedFile $file): string
    {
        return Str::limit($file->getClientOriginalName(), 255, '');
    }

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
        $contents = (string) file_get_contents($path);
        $originalName = self::originalName($file);
        $isText = AttachedText::accepts($originalName);

        if ($isText && trim(AttachedText::normalize($contents)) === '') {
            throw ValidationException::withMessages(['file' => __('The file is empty.')]);
        }

        if (! $isText && ! mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => __('The file must be UTF-8 text.')]);
        }

        $rows = $isText ? [] : $this->inspect($path);

        try {
            $media = DB::transaction(function () use ($user, $workspace, $file, $conversation, $originalName, $rows) {
                $conversation ??= $this->conversations->execute($user, $workspace, $originalName);

                return $conversation->addMedia($file)
                    ->usingFileName(Str::ulid().'.csv')
                    ->usingName(pathinfo($originalName, PATHINFO_FILENAME))
                    ->withAttributes(['workspace_id' => $workspace->getKey()])
                    ->addCustomHeaders(['ContentType' => 'text/plain; charset=utf-8'])
                    ->withCustomProperties(['original_name' => $originalName, ...$rows])
                    ->toMediaCollection(AgentConversation::ATTACHMENTS_MEDIA_COLLECTION);
            });
        } catch (FileUnacceptableForCollection) {
            throw ValidationException::withMessages(['file' => __('The file must be a CSV, TXT or MD file.')]);
        }

        return new ChatAttachment($media);
    }

    /** @return array{row_count: int, header: list<string>} */
    private function inspect(string $path): array
    {
        try {
            $inspection = $this->loader->inspect($path);
        } catch (ImportFileException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return ['row_count' => $inspection['row_count'], 'header' => $inspection['headers']];
    }
}
