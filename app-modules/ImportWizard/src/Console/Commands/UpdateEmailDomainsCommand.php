<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class UpdateEmailDomainsCommand extends Command
{
    /** @var string */
    protected $signature = 'import-wizard:update-email-domains
                            {--source=kikobeats : Source to fetch from (kikobeats)}
                            {--dry-run : Show what would be updated without saving}';

    /** @var string */
    protected $description = 'Update the public email domains list from external source';

    /** @var array<string, string> */
    private const array SOURCES = [
        'kikobeats' => 'https://raw.githubusercontent.com/Kikobeats/free-email-domains/master/domains.json',
    ];

    public function handle(): int
    {
        $source = $this->option('source');
        $url = self::SOURCES[$source] ?? null;

        if ($url === null) {
            $this->error("Unknown source: {$source}. Available: ".implode(', ', array_keys(self::SOURCES)));

            return self::FAILURE;
        }

        $this->info("Fetching domains from {$source}...");

        $response = Http::timeout(30)->get($url);

        if (! $response->successful()) {
            $this->error("Failed to fetch domains: HTTP {$response->status()}");

            return self::FAILURE;
        }

        $domains = $response->json();

        if (! is_array($domains)) {
            $this->error('Invalid response format: expected JSON array');

            return self::FAILURE;
        }

        $count = count($domains);
        $this->info("Fetched {$count} domains");

        if ($this->option('dry-run')) {
            $this->warn('Dry run - not saving');
            $this->table(['Sample Domains'], array_map(fn (string $d): array => [$d], array_slice($domains, 0, 10)));

            return self::SUCCESS;
        }

        $path = $this->getStoragePath();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, json_encode($domains, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("Saved {$count} domains to {$path}");

        return self::SUCCESS;
    }

    private function getStoragePath(): string
    {
        $configPath = config('import-wizard.public_email_domains.path');

        if (is_string($configPath) && $configPath !== '') {
            return $configPath;
        }

        return dirname(__DIR__, 3).'/storage/free-email-domains.json';
    }
}
