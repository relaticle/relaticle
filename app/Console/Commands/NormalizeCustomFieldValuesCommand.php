<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Support\CustomFields\CanonicalValue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

#[Description('Re-run every phone, link and domain custom field value through its field type normalizer')]
#[Signature('custom-fields:normalize-values {--force : Write changes instead of reporting them}')]
final class NormalizeCustomFieldValuesCommand extends Command
{
    private const string NATIONAL_PHONE = '/^(?=.*\d)[\d\s().\-]+(?:;ext=\d+)?$/';

    private int $changed = 0;

    private int $national = 0;

    private int $malformed = 0;

    private int $skipped = 0;

    private int $sharedDomains = 0;

    /** @var array<string, list<string>> */
    private array $domainOwners = [];

    public function handle(): int
    {
        $write = (bool) $this->option('force');
        $this->changed = 0;
        $this->national = 0;
        $this->malformed = 0;
        $this->skipped = 0;
        $this->sharedDomains = 0;

        CustomField::query()
            ->withoutGlobalScopes()
            ->whereIn('type', [CustomFieldType::PHONE->value, CustomFieldType::LINK->value, CustomFieldType::DOMAIN->value])
            ->eachById(function (CustomField $field) use ($write): void {
                $this->info("Normalizing {$field->entity_type}.{$field->code} in workspace {$field->tenant_id}...");
                $this->normalizeField($field, $write);
            });

        $this->comment($write
            ? "{$this->changed} value(s) changed."
            : "{$this->changed} value(s) would change. Re-run with --force to write.");

        if ($this->national > 0) {
            $this->comment("{$this->national} national phone number(s) have no country code and were left as they are.");
        }

        if ($this->malformed > 0) {
            $this->comment("{$this->malformed} value(s) have an unexpected shape and were left as they are.");
        }

        Log::info('custom-fields:normalize-values finished.', [
            'mode' => $write ? 'write' : 'report',
            'changed' => $this->changed,
            'skipped' => $this->skipped,
            'national_phones' => $this->national,
            'shared_domains' => $this->sharedDomains,
        ]);

        return self::SUCCESS;
    }

    private function normalizeField(CustomField $field, bool $write): void
    {
        $isDomain = $field->type === CustomFieldType::DOMAIN->value;
        $nationalBefore = $this->national;
        $this->domainOwners = [];

        DB::table('custom_field_values')
            ->where('custom_field_id', $field->getKey())
            ->whereNotNull('json_value')
            ->eachById(function (stdClass $row) use ($field, $isDomain, $write): void {
                try {
                    $this->normalizeRow($field, $row, $isDomain, $write);
                } catch (Throwable $exception) {
                    $reason = class_basename($exception);
                    $this->warn("Value {$row->id}: {$reason}, skipped.");
                    $this->logSkipped($field, $row, $reason);
                }
            }, 500);

        $this->reportCollisions($field);

        if ($this->national > $nationalBefore) {
            $this->comment('Workspace '.$field->tenant_id.': '.($this->national - $nationalBefore)." national phone number(s) in {$field->entity_type}.{$field->code}.");
        }
    }

    private function normalizeRow(CustomField $field, stdClass $row, bool $isDomain, bool $write): void
    {
        $stored = json_decode((string) $row->json_value, true);

        if ($stored === null) {
            return;
        }

        if (! is_array($stored) || ! array_is_list($stored) || ! array_all($stored, fn (mixed $item): bool => is_string($item))) {
            $this->malformed++;
            $this->logSkipped($field, $row, 'unexpected_shape');

            return;
        }

        $normalized = CanonicalValue::each($field, $stored);

        $this->countNational($field, $normalized);
        $this->collectDomains($isDomain, (string) $row->entity_id, $normalized);

        if ($normalized === $stored) {
            return;
        }

        if ($write && ! $this->storeIfUnchanged($field, $row, $normalized)) {
            $this->logSkipped($field, $row, 'changed_during_run');

            return;
        }

        $this->changed++;
    }

    private function logSkipped(CustomField $field, stdClass $row, string $reason): void
    {
        $this->skipped++;

        Log::warning('custom-fields:normalize-values skipped a value.', [
            'value_id' => $row->id,
            'field' => "{$field->entity_type}.{$field->code}",
            'workspace_id' => $field->tenant_id,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<int, string>  $normalized
     */
    private function storeIfUnchanged(CustomField $field, stdClass $row, array $normalized): bool
    {
        return DB::table('custom_field_values')
            ->where('custom_field_id', $field->getKey())
            ->where('id', $row->id)
            ->whereRaw('json_value::text = ?', [$row->json_value])
            ->update(['json_value' => json_encode($normalized)]) === 1;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function countNational(CustomField $field, array $values): void
    {
        if ($field->type !== CustomFieldType::PHONE->value) {
            return;
        }

        $this->national += count(array_filter($values, fn (string $value): bool => preg_match(self::NATIONAL_PHONE, $value) === 1));
    }

    /**
     * @param  array<int, string>  $values
     */
    private function collectDomains(bool $isDomain, string $entityId, array $values): void
    {
        if (! $isDomain) {
            return;
        }

        foreach ($values as $value) {
            $this->domainOwners[$value][] = $entityId;
        }
    }

    private function reportCollisions(CustomField $field): void
    {
        $live = $this->liveEntityIds($field);

        foreach ($this->domainOwners as $domain => $entityIds) {
            $owners = array_values(array_intersect(array_unique($entityIds), $live));

            if (count($owners) > 1) {
                $this->sharedDomains++;
                $this->warn("Workspace {$field->tenant_id}: {$domain} is shared by ".count($owners).' companies ('.implode(', ', $owners).').');
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function liveEntityIds(CustomField $field): array
    {
        $entity = CrmEntity::tryFrom($field->entity_type);

        if ($this->domainOwners === [] || ! $entity instanceof CrmEntity) {
            return [];
        }

        return DB::table($entity->table())
            ->whereIn('id', array_unique(array_merge(...array_values($this->domainOwners))))
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(strval(...))
            ->all();
    }
}
