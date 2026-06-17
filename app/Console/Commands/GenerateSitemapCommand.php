<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Relaticle\Ink\BlogSitemapGenerator;
use Spatie\Sitemap\SitemapGenerator;

#[Description('Generate the sitemap')]
#[Signature('app:generate-sitemap')]
final class GenerateSitemapCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $sitemap = SitemapGenerator::create(config('app.url'))->getSitemap();

        BlogSitemapGenerator::addToSitemap($sitemap);

        $sitemap->writeToFile(public_path('sitemap.xml'));
    }
}
