<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const int CHUNK_SIZE = 500;

    public function up(): void
    {
        if (! Schema::hasColumn('custom_fields', 'lookup_type')) {
            return;
        }

        DB::table('custom_fields')->where('type', 'record')->eachById(function (object $field): void {
            $this->convert($field);
        }, self::CHUNK_SIZE);

        Schema::table('custom_fields', function (Blueprint $table): void {
            $table->dropColumn('lookup_type');
        });
    }

    private function convert(object $field): void
    {
        $definition = $this->existingDefinition($field) ?? $this->createDefinition($field);

        if ($definition === null) {
            // A field with no target cannot point anywhere: it is switched off, and its ids stay for inspection.
            DB::table('custom_fields')->where('id', $field->id)->update(['active' => false]);

            return;
        }

        $readsFromEnd = (string) $definition->from_field_id === (string) $field->id;
        $targetType = $readsFromEnd ? $definition->to_entity_type : $definition->from_entity_type;
        $values = DB::table('custom_field_values')->where('custom_field_id', $field->id);

        (clone $values)->whereNotNull('json_value')->eachById(function (object $value) use ($definition, $field, $readsFromEnd, $targetType): void {
            $this->link($definition, $field, $value, $readsFromEnd, $targetType);
        }, self::CHUNK_SIZE);

        $values->delete();
    }

    private function existingDefinition(object $field): ?object
    {
        return DB::table('custom_field_relationships')
            ->where(fn (Builder $query): Builder => $query->where('from_field_id', $field->id)->orWhere('to_field_id', $field->id))
            ->first();
    }

    private function createDefinition(object $field): ?object
    {
        if (! is_string($field->lookup_type) || $field->lookup_type === '') {
            return null;
        }

        $settings = json_decode((string) $field->settings, true);
        $multiple = is_array($settings) && ($settings['allow_multiple'] ?? false) === true;

        $definition = [
            'id' => (string) Str::ulid(),
            'tenant_id' => $field->tenant_id,
            'code' => $this->availableCode($field),
            'from_entity_type' => $field->entity_type,
            'to_entity_type' => $field->lookup_type,
            'cardinality' => $multiple ? 'many_to_many' : 'many_to_one',
            'is_symmetric' => false,
            'from_field_id' => $field->id,
            'to_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('custom_field_relationships')->insert($definition);

        return (object) $definition;
    }

    private function link(object $definition, object $field, object $value, bool $readsFromEnd, string $targetType): void
    {
        $ids = json_decode((string) $value->json_value, true);

        if (! is_array($ids)) {
            return;
        }

        $targets = $this->existingIds($targetType, $ids);
        $linked = $this->linkedTargets($definition, $value, $readsFromEnd);
        $position = 0;

        foreach ($ids as $id) {
            $id = (string) $id;

            if (! in_array($id, $targets, true) || in_array($id, $linked, true)) {
                continue;
            }

            $near = ['type' => $value->entity_type, 'id' => $value->entity_id];
            $far = ['type' => $targetType, 'id' => $id];
            [$from, $to] = $readsFromEnd ? [$near, $far] : [$far, $near];

            DB::table('custom_field_links')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $field->tenant_id,
                'relationship_id' => $definition->id,
                'from_entity_type' => $from['type'],
                'from_entity_id' => $from['id'],
                'to_entity_type' => $to['type'],
                'to_entity_id' => $to['id'],
                'sort_order' => $position++,
                'active_from' => now(),
                'source' => 'migration',
            ]);

            $linked[] = $id;
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<string>
     */
    private function existingIds(string $entityType, array $ids): array
    {
        // Only the morph map knows a target's table. Soft-deleted rows count, so their links survive.
        $class = Relation::getMorphedModel($entityType) ?? $entityType;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return [];
        }

        $table = (new $class)->getTable();

        return DB::table($table)
            ->whereIn('id', array_values(array_filter($ids, is_scalar(...))))
            ->pluck('id')
            ->map(static fn (mixed $key): string => (string) $key)
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function linkedTargets(object $definition, object $value, bool $readsFromEnd): array
    {
        [$near, $far] = $readsFromEnd ? ['from', 'to'] : ['to', 'from'];

        return DB::table('custom_field_links')
            ->where('relationship_id', $definition->id)
            ->where($near.'_entity_type', $value->entity_type)
            ->where($near.'_entity_id', $value->entity_id)
            ->pluck($far.'_entity_id')
            ->map(static fn (mixed $key): string => (string) $key)
            ->values()
            ->all();
    }

    private function availableCode(object $field): string
    {
        $candidate = $field->code;
        $suffix = 1;

        while (DB::table('custom_field_relationships')->where('tenant_id', $field->tenant_id)->where('code', $candidate)->exists()) {
            $candidate = sprintf('%s_%d', $field->code, $suffix++);
        }

        return $candidate;
    }
};
