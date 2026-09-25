<?php

declare(strict_types=1);

namespace Relaticle\Chat\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Relaticle\Chat\Actions\DeleteChatAttachment;
use Relaticle\Chat\Actions\ImportAttachment;
use Relaticle\Chat\Actions\StoreChatAttachment;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Filament\Pages\ImportCompanies;
use Relaticle\ImportWizard\Filament\Pages\ImportPeople;

final readonly class ChatAttachmentController
{
    public function __construct(
        private StoreChatAttachment $store,
        private ImportAttachment $import,
        private DeleteChatAttachment $delete,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.StoreChatAttachment::MAX_KILOBYTES, 'mimes:csv,txt'],
            'conversation_id' => ['nullable', 'string', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $attachment = $this->store->execute($user, $file, $validated['conversation_id'] ?? null);

        return response()->json([
            ...$attachment->meta(),
            'header' => $attachment->header(),
            'conversation_id' => $attachment->conversationId(),
        ]);
    }

    public function destroy(Request $request, string $attachment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($this->delete->execute($user, $attachment), 404);

        return response()->json(['success' => true]);
    }

    public function import(Request $request, string $attachment, string $entity): RedirectResponse
    {
        $entityType = ImportEntityType::tryFrom($entity);

        abort_if($entityType === null, 404);

        /** @var User $user */
        $user = $request->user();

        $import = $this->import->execute($user, $attachment, $entityType);

        $page = $entityType === ImportEntityType::People ? ImportPeople::class : ImportCompanies::class;

        return redirect()->to($page::getUrl(['import' => $import->id], panel: 'app', tenant: $user->currentWorkspace));
    }
}
