<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Upload\PurgeExpiredUploads;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Delete pending uploads and signed-put temp files nobody claimed within a day')]
#[Signature('app:purge-pending-uploads {--hours=24 : Age after which an unclaimed upload is deleted}')]
final class PurgePendingUploadsCommand extends Command
{
    public function handle(PurgeExpiredUploads $purge): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($hours === false) {
            $this->error(__('uploads.errors.invalid_retention'));

            return self::FAILURE;
        }

        $cutoff = now()->subHours($hours);

        $deleted = $purge->execute($cutoff);

        $this->comment("Purged {$deleted['media']} pending upload(s) and {$deleted['temporary']} temp file(s).");

        return self::SUCCESS;
    }
}
