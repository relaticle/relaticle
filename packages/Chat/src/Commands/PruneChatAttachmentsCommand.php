<?php

declare(strict_types=1);

namespace Relaticle\Chat\Commands;

use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Description('Delete chat attachments and their files after their retention window')]
#[Signature('chat:prune-attachments {--hours=24 : Delete attachments older than this many hours}')]
final class PruneChatAttachmentsCommand extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subHours((int) $this->option('hours'));

        $expired = Media::query()
            ->where('collection_name', Workspace::CHAT_ATTACHMENTS_MEDIA_COLLECTION)
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($expired as $media) {
            $this->info("Pruning attachment {$media->uuid}");
            $media->delete();
        }

        $this->comment("Pruned {$expired->count()} attachment(s).");

        return self::SUCCESS;
    }
}
