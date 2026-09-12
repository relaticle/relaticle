<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\Services\TenantContextService;

$markdown = "# Meeting notes\n\n- first point\n- second point";

$migration = fn (): mixed => (require base_path('database/migrations/2026_09_10_000000_convert_markdown_editor_custom_fields_to_rich_editor.php'))->up();

$markdownField = function (string $code, bool $encrypted): CustomField {
    $settings = new CustomFieldSettingsData;
    $settings->encrypted = $encrypted;

    return CustomField::forceCreate([
        'name' => "MD {$code}",
        'code' => $code,
        'type' => 'markdown-editor',
        'entity_type' => 'note',
        'tenant_id' => test()->team->id,
        'sort_order' => 90,
        'active' => true,
        'system_defined' => false,
        'settings' => $settings,
    ]);
};

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->team = $this->user->currentTeam;
    TenantContextService::setTenantId($this->team->id);
});

it('no longer offers the markdown editor as a field type', function (): void {
    $keys = CustomFieldsType::toCollection()->pluck('key')->all();

    expect($keys)->not->toContain('markdown-editor')
        ->and($keys)->toContain('rich-editor');
});

it('moves markdown fields to the rich editor and converts their content', function () use ($markdown, $migration, $markdownField): void {
    $plain = $markdownField('md_plain', encrypted: false);
    $encrypted = $markdownField('md_encrypted', encrypted: true);

    DB::table('custom_field_values')->insert([
        ['id' => (string) Str::ulid(), 'custom_field_id' => $plain->id, 'entity_id' => (string) Str::ulid(), 'entity_type' => 'note', 'tenant_id' => $this->team->id, 'text_value' => $markdown],
        ['id' => (string) Str::ulid(), 'custom_field_id' => $encrypted->id, 'entity_id' => (string) Str::ulid(), 'entity_type' => 'note', 'tenant_id' => $this->team->id, 'text_value' => Crypt::encryptString($markdown)],
    ]);

    $migration();

    $value = fn (CustomField $field): ?string => DB::table('custom_field_values')->where('custom_field_id', $field->id)->value('text_value');

    expect(DB::table('custom_fields')->whereIn('id', [$plain->id, $encrypted->id])->pluck('type')->unique()->all())
        ->toBe(['rich-editor'])
        ->and($value($plain))->toContain('<h1>Meeting notes</h1>', '<li>first point</li>')
        ->and(Crypt::decryptString($value($encrypted)))->toContain('<h1>Meeting notes</h1>');
});

it('converts every value when a blank value empties out mid-run', function () use ($markdown, $migration, $markdownField): void {
    $field = $markdownField('md_many', encrypted: false);

    $ids = collect(range(1, 1001))->map(fn (): string => (string) Str::ulid())->sort()->values();

    DB::table('custom_field_values')->insert($ids->map(fn (string $id, int $index): array => [
        'id' => $id,
        'custom_field_id' => $field->id,
        'entity_id' => (string) Str::ulid(),
        'entity_type' => 'note',
        'tenant_id' => $this->team->id,
        'text_value' => $index === 0 ? '   ' : $markdown,
    ])->all());

    $migration();

    $unconverted = DB::table('custom_field_values')
        ->where('custom_field_id', $field->id)
        ->where('text_value', $markdown)
        ->count();

    expect($unconverted)->toBe(0);
});

it('leaves a markdown field without a value alone', function () use ($migration, $markdownField): void {
    $field = $markdownField('md_empty', encrypted: false);

    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'custom_field_id' => $field->id,
        'entity_id' => (string) Str::ulid(),
        'entity_type' => 'note',
        'tenant_id' => $this->team->id,
        'text_value' => null,
    ]);

    $migration();

    expect(DB::table('custom_fields')->where('id', $field->id)->value('type'))->toBe('rich-editor')
        ->and(DB::table('custom_field_values')->where('custom_field_id', $field->id)->value('text_value'))->toBeNull();
});
