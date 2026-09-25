<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Support\ChatAttachment;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Exceptions\ImportFileException;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Support\ImportFileLoader;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class ImportAttachment
{
    /** @var list<ImportEntityType> */
    public const array ENTITY_TYPES = [ImportEntityType::People, ImportEntityType::Company];

    public function __construct(private ImportFileLoader $loader) {}

    public function execute(User $user, string $attachmentId, ImportEntityType $entityType): Import
    {
        abort_unless(in_array($entityType, self::ENTITY_TYPES, true), 404);

        $workspace = $user->currentWorkspace;

        abort_if($workspace === null, 403);

        $modelClass = $entityType === ImportEntityType::People ? People::class : Company::class;

        abort_unless($user->can('create', $modelClass), 403);

        $attachment = ChatAttachment::find($user, $attachmentId);

        abort_if(! $attachment instanceof ChatAttachment, 404);

        abort_unless($attachment->fileExists(), 410);

        return DB::transaction(function () use ($attachment, $entityType, $workspace, $user): Import {
            $media = Media::query()->whereKey($attachment->media->getKey())->lockForUpdate()->firstOrFail();
            $locked = new ChatAttachment($media);

            $existingId = $locked->importIdFor($entityType);

            if ($existingId !== null) {
                $existing = Import::query()->forWorkspace((string) $workspace->getKey())->find($existingId);

                if ($existing instanceof Import) {
                    return $existing;
                }
            }

            try {
                $import = $this->loader->load(
                    $locked->absolutePath(),
                    $locked->name(),
                    $entityType,
                    (string) $workspace->getKey(),
                    (string) $user->getKey(),
                );
            } catch (ImportFileException $e) {
                abort(422, $e->getMessage());
            }

            $media->setCustomProperty("imports.{$entityType->value}", $import->id)->save();

            return $import;
        });
    }
}
