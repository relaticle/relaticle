<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Relaticle\Cloud\Blog\Jobs\GenerateBlogCover;
use Relaticle\Ink\Models\Post;
use Throwable;

#[Signature('blog:backfill-covers {--force : Generate missing or changed covers} {--queue : Dispatch one job per cover}')]
#[Description('Measure blog covers and generate responsive WebP images')]
final class BackfillBlogCoversCommand extends Command
{
    public function handle(): int
    {
        $sources = Post::query()->where('featured_image', '!=', '')->select('featured_image')->distinct()->toBase();
        if (! $this->option('force')) {
            $this->info($sources->count('featured_image').' distinct covers. Use --force to process them.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($sources->lazyById(50, 'featured_image') as $post) {
            $job = new GenerateBlogCover($post->featured_image);
            if ($this->option('queue')) {
                dispatch($job);

                continue;
            }

            try {
                dispatch($job)->onConnection('sync');
            } catch (Throwable $exception) {
                $this->error("{$post->featured_image}: {$exception->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
