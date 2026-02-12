<?php

declare(strict_types=1);

use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\NumberFormat;
use Relaticle\ImportWizard\Support\Validation\ColumnValidator;
use Relaticle\ImportWizard\Support\Validation\ValidationError;

mutates(ColumnValidator::class);

function makeColumnData(
    FieldDataType $type,
    array $rules = [],
    bool $arbitrary = false,
    ?array $options = null,
): ColumnData {
    $column = ColumnData::toField(source: 'Test', target: 'test');
    $column->importField = new ImportField(
        key: 'test',
        label: 'Test',
        rules: $rules,
        type: $type,
        acceptsArbitraryValues: $arbitrary,
        options: $options,
    );

    return $column;
}

// ─── Text Validation ────────────────────────────────────────────────────────

it('passes valid text with no rules', function (): void {
    $column = makeColumnData(FieldDataType::STRING);
    $validator = new ColumnValidator;

    expect($validator->validate($column, 'hello world'))->toBeNull();
});

it('rejects invalid email in text field with email rule', function (): void {
    $column = makeColumnData(FieldDataType::STRING, rules: ['email', 'max:254']);
    $validator = new ColumnValidator;

    $error = $validator->validate($column, 'not-an-email');

    expect($error)
        ->toBeInstanceOf(ValidationError::class)
        ->getMessage()->not->toBeNull();
});

// ─── Multi-Choice Arbitrary ─────────────────────────────────────────────────

it('passes all valid emails in multi-choice arbitrary field', function (): void {
    $column = makeColumnData(
        FieldDataType::MULTI_CHOICE,
        rules: ['email', 'max:254'],
        arbitrary: true,
    );
    $validator = new ColumnValidator;

    expect($validator->validate($column, 'alice@test.com, bob@test.com'))->toBeNull();
});

it('returns per-item errors for invalid emails in multi-choice arbitrary field', function (): void {
    $column = makeColumnData(
        FieldDataType::MULTI_CHOICE,
        rules: ['email', 'max:254'],
        arbitrary: true,
    );
    $validator = new ColumnValidator;

    $error = $validator->validate($column, 'valid@test.com, not-an-email, another-bad');

    expect($error)
        ->toBeInstanceOf(ValidationError::class)
        ->hasItemErrors()->toBeTrue();

    $itemErrors = $error->getItemErrors();
    expect($itemErrors)
        ->toHaveKey('not-an-email')
        ->toHaveKey('another-bad')
        ->not->toHaveKey('valid@test.com');
});

it('filters out empty items from trailing commas', function (): void {
    $column = makeColumnData(
        FieldDataType::MULTI_CHOICE,
        rules: ['email', 'max:254'],
        arbitrary: true,
    );
    $validator = new ColumnValidator;

    expect($validator->validate($column, 'valid@test.com, , ,'))->toBeNull();
});

it('passes multi-choice arbitrary with no rules', function (): void {
    $column = makeColumnData(
        FieldDataType::MULTI_CHOICE,
        rules: [],
        arbitrary: true,
    );
    $validator = new ColumnValidator;

    expect($validator->validate($column, 'anything, goes, here'))->toBeNull();
});

// ─── Multi-Choice Predefined ────────────────────────────────────────────────

it('passes all valid options in multi-choice predefined field', function (): void {
    $column = makeColumnData(
        FieldDataType::MULTI_CHOICE,
        options: [
            ['label' => 'Red', 'value' => 'red'],
            ['label' => 'Blue', 'value' => 'blue'],
        ],
    );
    $validator = new ColumnValidator;

    expect($validator->validate($column, 'red, blue'))->toBeNull();
});

it('returns per-item errors for invalid options in multi-choice predefined field', function (): void {
    $column = makeColumnData(
        FieldDataType::MULTI_CHOICE,
        options: [
            ['label' => 'Red', 'value' => 'red'],
            ['label' => 'Blue', 'value' => 'blue'],
        ],
    );
    $validator = new ColumnValidator;

    $error = $validator->validate($column, 'red, green, purple');

    expect($error)
        ->toBeInstanceOf(ValidationError::class)
        ->hasItemErrors()->toBeTrue();

    $itemErrors = $error->getItemErrors();
    expect($itemErrors)
        ->toHaveKey('green')
        ->toHaveKey('purple')
        ->not->toHaveKey('red');

    expect($itemErrors['green'])->toBe('Not a valid option');
});

// ─── Single-Choice Predefined ───────────────────────────────────────────────

it('passes valid option in single-choice predefined field', function (): void {
    $column = makeColumnData(
        FieldDataType::SINGLE_CHOICE,
        options: [
            ['label' => 'Active', 'value' => 'active'],
            ['label' => 'Inactive', 'value' => 'inactive'],
        ],
    );
    $validator = new ColumnValidator;

    expect($validator->validate($column, 'active'))->toBeNull();
});

it('rejects invalid option in single-choice predefined field', function (): void {
    $column = makeColumnData(
        FieldDataType::SINGLE_CHOICE,
        options: [
            ['label' => 'Active', 'value' => 'active'],
            ['label' => 'Inactive', 'value' => 'inactive'],
        ],
    );
    $validator = new ColumnValidator;

    $error = $validator->validate($column, 'unknown');

    expect($error)
        ->toBeInstanceOf(ValidationError::class)
        ->getMessage()->toContain('Invalid choice');
});

// ─── Date Validation ────────────────────────────────────────────────────────

it('passes valid ISO date', function (): void {
    $column = makeColumnData(FieldDataType::DATE);
    $validator = new ColumnValidator;

    expect($validator->validate($column, '2024-05-15'))->toBeNull();
});

it('rejects invalid date format', function (): void {
    $column = makeColumnData(FieldDataType::DATE);
    $validator = new ColumnValidator;

    $error = $validator->validate($column, 'not-a-date');

    expect($error)
        ->toBeInstanceOf(ValidationError::class)
        ->getMessage()->toContain('Invalid date format');
});

it('validates date with custom European format', function (): void {
    $column = makeColumnData(FieldDataType::DATE);
    $column = $column->withDateFormat(DateFormat::EUROPEAN);

    $validator = new ColumnValidator;

    expect($validator->validate($column, '15/05/2024'))->toBeNull();
    expect($validator->validate($column, '2024-05-15'))
        ->toBeInstanceOf(ValidationError::class)
        ->getMessage()->toContain('European');
});

it('validates date with custom American format', function (): void {
    $column = makeColumnData(FieldDataType::DATE);
    $column = $column->withDateFormat(DateFormat::AMERICAN);

    $validator = new ColumnValidator;

    expect($validator->validate($column, '05/15/2024'))->toBeNull();
});

// ─── Number Validation ─────────────────────────────────────────────────────

it('passes valid number with point format', function (): void {
    $column = makeColumnData(FieldDataType::FLOAT);
    $validator = new ColumnValidator;

    expect($validator->validate($column, '1234.56'))->toBeNull();
});

it('rejects invalid number value', function (): void {
    $column = makeColumnData(FieldDataType::FLOAT);
    $validator = new ColumnValidator;

    $error = $validator->validate($column, 'not-a-number');

    expect($error)
        ->toBeInstanceOf(ValidationError::class)
        ->getMessage()->toContain('Invalid number format');
});

it('validates number with custom comma format', function (): void {
    $column = makeColumnData(FieldDataType::FLOAT);
    $column = $column->withNumberFormat(NumberFormat::COMMA);

    $validator = new ColumnValidator;

    expect($validator->validate($column, '1.234,56'))->toBeNull();
    expect($validator->validate($column, 'abc'))
        ->toBeInstanceOf(ValidationError::class)
        ->getMessage()->toContain('Comma');
});

// ─── Edge Cases ────────────────────────────────────────────────────────────

it('strips required and nullable from preview rules', function (): void {
    $column = makeColumnData(FieldDataType::STRING, rules: ['required', 'email', 'nullable']);
    $validator = new ColumnValidator;

    expect($validator->validate($column, 'valid@test.com'))->toBeNull();
    expect($validator->validate($column, 'not-an-email'))
        ->toBeInstanceOf(ValidationError::class);
});

it('returns valid for text with only required/nullable rules', function (): void {
    $column = makeColumnData(FieldDataType::STRING, rules: ['required', 'nullable']);
    $validator = new ColumnValidator;

    expect($validator->validate($column, 'anything'))->toBeNull();
});

it('returns valid for multi-choice arbitrary with only required rule', function (): void {
    $column = makeColumnData(
        FieldDataType::MULTI_CHOICE,
        rules: ['required'],
        arbitrary: true,
    );
    $validator = new ColumnValidator;

    expect($validator->validate($column, 'anything, goes'))->toBeNull();
});

it('formats choice error message with truncation for many options', function (): void {
    $options = [];
    for ($i = 1; $i <= 7; $i++) {
        $options[] = ['label' => "Option {$i}", 'value' => "opt{$i}"];
    }

    $column = makeColumnData(FieldDataType::SINGLE_CHOICE, options: $options);
    $validator = new ColumnValidator;

    $error = $validator->validate($column, 'invalid');

    expect($error)->toBeInstanceOf(ValidationError::class);

    $message = $error->getMessage();
    expect($message)
        ->toContain('opt1')
        ->toContain('opt5')
        ->not->toContain('opt6')
        ->toContain('...');
});

it('formats choice error message without truncation for few options', function (): void {
    $options = [
        ['label' => 'A', 'value' => 'a'],
        ['label' => 'B', 'value' => 'b'],
        ['label' => 'C', 'value' => 'c'],
    ];

    $column = makeColumnData(FieldDataType::SINGLE_CHOICE, options: $options);
    $validator = new ColumnValidator;

    $error = $validator->validate($column, 'invalid');

    $message = $error->getMessage();
    expect($message)
        ->toContain('a')
        ->toContain('b')
        ->toContain('c')
        ->not->toContain('...');
});

it('formats choice error message with exactly 5 options without ellipsis', function (): void {
    $options = [];
    for ($i = 1; $i <= 5; $i++) {
        $options[] = ['label' => "Option {$i}", 'value' => "opt{$i}"];
    }

    $column = makeColumnData(FieldDataType::SINGLE_CHOICE, options: $options);
    $validator = new ColumnValidator;

    $error = $validator->validate($column, 'invalid');

    expect($error->getMessage())->not->toContain('...');
});
