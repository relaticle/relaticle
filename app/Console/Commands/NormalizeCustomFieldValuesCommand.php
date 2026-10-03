<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CustomField;
use App\Support\CustomFields\CanonicalValue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Description('Re-run every phone and link custom field value through its field type normalizer')]
#[Signature('custom-fields:normalize-values {--force : Write changes instead of reporting them}')]
final class NormalizeCustomFieldValuesCommand extends Command
{
    private int $changed = 0;

    private int $national = 0;

    /** @var array<string, array<string, list<string>>> */
    private array $domainOwners = [];

    public function handle(): int
    {
        $write = (bool) $this->option('force');
        $this->changed = 0;
        $this->national = 0;
        $this->domainOwners = [];

        CustomField::query()
            ->withoutGlobalScopes()
            ->whereIn('type', ['phone', 'link'])
            ->eachById(function (CustomField $field) use ($write): void {
                $this->info("Normalizing {$field->entity_type}.{$field->code} in workspace {$field->tenant_id}...");
                $this->normalizeField($field, $write);
            });

        $this->reportCollisions();

        $this->comment($write
            ? "{$this->changed} value(s) changed."
            : "{$this->changed} value(s) would change. Re-run with --force to write.");

        if ($this->national > 0) {
            $this->comment("{$this->national} national phone number(s) have no country code and were left as they are.");
        }

        return self::SUCCESS;
    }

    private function normalizeField(CustomField $field, bool $write): void
    {
        $isDomain = $field->type === 'link' && $field->setting('link_variant') === 'domain';

        DB::table('custom_field_values')
            ->where('custom_field_id', $field->getKey())
            ->whereNotNull('json_value')
            ->chunkById(500, function (Collection $rows) use ($field, $isDomain, $write): void {
                foreach ($rows as $row) {
                    $stored = json_decode((string) $row->json_value, true);

                    if (! is_array($stored)) {
                        continue;
                    }

                    $normalized = CanonicalValue::each($field, $stored);

                    $this->countNational($field, $normalized);
                    $this->collectDomains($field, $isDomain, (string) $row->entity_id, $normalized);

                    if ($normalized === $stored) {
                        continue;
                    }

                    $this->changed++;

                    if ($write) {
                        DB::table('custom_field_values')->where('id', $row->id)->update(['json_value' => json_encode($normalized)]);
                    }
                }
            });
    }

    /**
     * @param  array<int, string>  $values
     */
    private function countNational(CustomField $field, array $values): void
    {
        if ($field->type !== 'phone') {
            return;
        }

        $this->national += count(array_filter($values, fn (string $value): bool => ! str_starts_with($value, '+')));
    }

    /**
     * @param  array<int, string>  $values
     */
    private function collectDomains(CustomField $field, bool $isDomain, string $entityId, array $values): void
    {
        if (! $isDomain) {
            return;
        }

        foreach ($values as $value) {
            $this->domainOwners[$field->tenant_id][$value][] = $entityId;
        }
    }

    private function reportCollisions(): void
    {
        foreach ($this->domainOwners as $workspaceId => $domains) {
            foreach ($domains as $domain => $entityIds) {
                $owners = array_values(array_unique($entityIds));

                if (count($owners) > 1) {
                    $this->warn("Workspace {$workspaceId}: {$domain} is shared by ".count($owners).' companies ('.implode(', ', $owners).').');
                }
            }
        }
    }
}
