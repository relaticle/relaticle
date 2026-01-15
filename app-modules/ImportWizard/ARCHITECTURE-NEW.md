# ImportWizard - Reimagined Architecture

> A clean, strongly-typed, performant architecture following Taylor's doctrine:
> Simple, disposable, easy to change.

---

## Design Philosophy

### Core Principles

1. **Thin Livewire, Fat Services** - Components orchestrate, services do work
2. **Single Source of Truth** - One place for each responsibility
3. **Strong Typing Everywhere** - No `mixed`, no `array<string, mixed>`
4. **Immutable Data Objects** - Value objects over arrays
5. **Explicit over Magic** - No hidden coupling via traits
6. **Testable by Design** - Each class easily unit testable

### What Changes

| Current | New |
|---------|-----|
| 898-line Livewire component | ~200-line orchestrator |
| 4 tightly-coupled traits | Composable services |
| Validation in 4+ places | Single `ValidationService` |
| Cache keys hardcoded | `ImportSession` value object |
| `array<string, mixed>` everywhere | Typed DTOs |
| API duplicates Livewire logic | Shared services |

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              PRESENTATION LAYER                              │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │                    ImportWizard (Livewire)                            │  │
│  │                    ~200 lines - UI orchestration only                 │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
│  ┌─────────────────────┐  ┌─────────────────────┐  ┌──────────────────────┐│
│  │ ImportValuesController│ │ImportCorrectionsCtrl│ │ ImportPreviewController││
│  │ (thin - delegates)    │ │(thin - delegates)   │ │ (thin - delegates)    ││
│  └─────────────────────┘  └─────────────────────┘  └──────────────────────┘│
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                              APPLICATION LAYER                               │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │                         ImportSession                                  │  │
│  │              (Value Object - Single source of truth)                   │  │
│  │         Wraps all session state: files, mappings, analysis            │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
│                                      │                                       │
│     ┌────────────────────────────────┼────────────────────────────────┐     │
│     ▼                                ▼                                ▼     │
│  ┌──────────────┐  ┌──────────────────────┐  ┌──────────────────────────┐  │
│  │ CsvService   │  │ ColumnMappingService │  │ ValueValidationService   │  │
│  │ - parse      │  │ - autoMap            │  │ - validateValue          │  │
│  │ - persist    │  │ - detectTypes        │  │ - validateColumn         │  │
│  │ - stream     │  │ - matchRelationships │  │ - parseDatePreview       │  │
│  └──────────────┘  └──────────────────────┘  └──────────────────────────┘  │
│                                                                              │
│  ┌──────────────────────────┐  ┌────────────────────────────────────────┐  │
│  │ ImportPreviewService     │  │ ImportExecutionService                  │  │
│  │ - generatePreview        │  │ - execute                               │  │
│  │ - processChunk           │  │ - dispatchJobs                          │  │
│  │ - resolveRelationships   │  │ - applyCorrections                      │  │
│  └──────────────────────────┘  └────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                                DOMAIN LAYER                                  │
│  ┌────────────────────────────────────────────────────────────────────────┐│
│  │                          Value Objects (DTOs)                          ││
│  │  ImportSession, ColumnMapping, ColumnAnalysis, ValueIssue,             ││
│  │  CsvFile, ImportResult, ValidationResult, RelationshipMatch            ││
│  └────────────────────────────────────────────────────────────────────────┘│
│  ┌────────────────────────────────────────────────────────────────────────┐│
│  │                              Enums                                      ││
│  │  ImportStep, DateFormat, TimestampFormat, IssueSeverity,               ││
│  │  MatcherType, ImportAction, DuplicateStrategy                          ││
│  └────────────────────────────────────────────────────────────────────────┘│
│  ┌────────────────────────────────────────────────────────────────────────┐│
│  │                         Entity Importers                                ││
│  │  CompanyImporter, PeopleImporter, OpportunityImporter, etc.            ││
│  └────────────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            INFRASTRUCTURE LAYER                              │
│  ┌──────────────────┐  ┌──────────────────┐  ┌────────────────────────────┐│
│  │ ImportCache      │  │ ImportStorage    │  │ StreamingImportJob         ││
│  │ - typed get/set  │  │ - temp files     │  │ ProcessImportPreviewJob    ││
│  │ - TTL management │  │ - permanent files│  │ CleanupImportFilesJob      ││
│  └──────────────────┘  └──────────────────┘  └────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Layer 1: Value Objects (The Foundation)

Everything flows through strongly-typed value objects. No more `array<string, mixed>`.

### ImportSession - The Central State Object

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\ValueObjects;

use Relaticle\ImportWizard\Enums\ImportStep;
use Spatie\LaravelData\Data;

/**
 * Immutable representation of an import session's complete state.
 *
 * This is THE source of truth. All components read from and write to this.
 * Persisted to cache, not Livewire state.
 */
final class ImportSession extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $teamId,
        public readonly string $userId,
        public readonly ImportStep $currentStep,
        public readonly ?CsvFile $csvFile,
        public readonly ColumnMappingCollection $mappings,
        public readonly ColumnAnalysisCollection $analyses,
        public readonly CorrectionCollection $corrections,
        public readonly ?ImportPreviewResult $previewResult,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $lastActivityAt,
    ) {}

    public function withStep(ImportStep $step): self
    {
        return new self(
            id: $this->id,
            teamId: $this->teamId,
            userId: $this->userId,
            currentStep: $step,
            csvFile: $this->csvFile,
            mappings: $this->mappings,
            analyses: $this->analyses,
            corrections: $this->corrections,
            previewResult: $this->previewResult,
            createdAt: $this->createdAt,
            lastActivityAt: new \DateTimeImmutable(),
        );
    }

    public function withCsvFile(CsvFile $file): self
    {
        return new self(
            id: $this->id,
            teamId: $this->teamId,
            userId: $this->userId,
            currentStep: $this->currentStep,
            csvFile: $file,
            mappings: $this->mappings,
            analyses: $this->analyses,
            corrections: $this->corrections,
            previewResult: $this->previewResult,
            createdAt: $this->createdAt,
            lastActivityAt: new \DateTimeImmutable(),
        );
    }

    public function withMappings(ColumnMappingCollection $mappings): self
    {
        return new self(
            id: $this->id,
            teamId: $this->teamId,
            userId: $this->userId,
            currentStep: $this->currentStep,
            csvFile: $this->csvFile,
            mappings: $mappings,
            analyses: $this->analyses,
            corrections: $this->corrections,
            previewResult: $this->previewResult,
            createdAt: $this->createdAt,
            lastActivityAt: new \DateTimeImmutable(),
        );
    }

    // ... more with* methods for immutable updates
}
```

### CsvFile - Typed File Metadata

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\ValueObjects;

final class CsvFile extends Data
{
    /**
     * @param non-empty-string $path
     * @param non-empty-string $originalName
     * @param list<non-empty-string> $headers
     * @param positive-int $rowCount
     * @param positive-int $columnCount
     */
    public function __construct(
        public readonly string $path,
        public readonly string $originalName,
        public readonly int $sizeBytes,
        public readonly array $headers,
        public readonly int $rowCount,
        public readonly int $columnCount,
    ) {}

    public function isWithinLimits(int $maxRows, int $maxSizeBytes): bool
    {
        return $this->rowCount <= $maxRows && $this->sizeBytes <= $maxSizeBytes;
    }
}
```

### ColumnMapping - Single Column Mapping

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\ValueObjects;

use Relaticle\CustomFields\Enums\FieldDataType;

final class ColumnMapping extends Data
{
    /**
     * @param non-empty-string $csvColumn
     * @param non-empty-string $fieldName
     */
    public function __construct(
        public readonly string $csvColumn,
        public readonly string $fieldName,
        public readonly string $fieldLabel,
        public readonly FieldDataType $fieldType,
        public readonly bool $isRequired,
        public readonly bool $isCustomField,
        public readonly bool $isRelationship,
        public readonly ?RelationshipMapping $relationshipMapping,
        public readonly MappingSource $source, // AUTO, INFERRED, MANUAL
    ) {}

    public function isDateField(): bool
    {
        return $this->fieldType === FieldDataType::DATE
            || $this->fieldType === FieldDataType::DATE_TIME;
    }

    public function isChoiceField(): bool
    {
        return $this->fieldType->isChoiceField();
    }
}
```

### ColumnMappingCollection - Type-Safe Collection

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\ValueObjects;

use Illuminate\Support\Collection;

/**
 * @extends Collection<string, ColumnMapping>
 */
final class ColumnMappingCollection extends Collection
{
    public function getMappingForField(string $fieldName): ?ColumnMapping
    {
        return $this->get($fieldName);
    }

    public function getMappingForCsvColumn(string $csvColumn): ?ColumnMapping
    {
        return $this->first(fn (ColumnMapping $m) => $m->csvColumn === $csvColumn);
    }

    public function getRequiredFields(): self
    {
        return $this->filter(fn (ColumnMapping $m) => $m->isRequired);
    }

    public function getUnmappedRequired(): self
    {
        return $this->getRequiredFields()->filter(fn (ColumnMapping $m) => $m->csvColumn === '');
    }

    public function hasAllRequiredMappings(): bool
    {
        return $this->getUnmappedRequired()->isEmpty();
    }

    public function toColumnMap(): array
    {
        return $this->mapWithKeys(fn (ColumnMapping $m) => [$m->fieldName => $m->csvColumn])->all();
    }
}
```

### ColumnAnalysis - Analysis Results

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\ValueObjects;

use Relaticle\ImportWizard\Enums\DateFormat;

final class ColumnAnalysis extends Data
{
    /**
     * @param array<string, positive-int> $uniqueValues Value => occurrence count
     */
    public function __construct(
        public readonly string $csvColumn,
        public readonly string $fieldName,
        public readonly array $uniqueValues,
        public readonly ValueIssueCollection $issues,
        public readonly int $totalRows,
        public readonly int $blankCount,
        public readonly ?DateFormat $detectedFormat,
        public readonly ?DateFormat $selectedFormat,
        public readonly ?float $formatConfidence,
    ) {}

    public function uniqueCount(): int
    {
        return count($this->uniqueValues);
    }

    public function errorCount(): int
    {
        return $this->issues->errorCount();
    }

    public function warningCount(): int
    {
        return $this->issues->warningCount();
    }

    public function hasErrors(): bool
    {
        return $this->errorCount() > 0;
    }

    public function needsFormatConfirmation(): bool
    {
        return $this->formatConfidence !== null
            && $this->formatConfidence < 0.8
            && $this->selectedFormat === null;
    }

    public function effectiveFormat(): ?DateFormat
    {
        return $this->selectedFormat ?? $this->detectedFormat;
    }

    public function withSelectedFormat(DateFormat $format): self
    {
        return new self(
            csvColumn: $this->csvColumn,
            fieldName: $this->fieldName,
            uniqueValues: $this->uniqueValues,
            issues: $this->issues,
            totalRows: $this->totalRows,
            blankCount: $this->blankCount,
            detectedFormat: $this->detectedFormat,
            selectedFormat: $format,
            formatConfidence: $this->formatConfidence,
        );
    }
}
```

### ValueIssue - Single Validation Issue

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\ValueObjects;

use Relaticle\ImportWizard\Enums\IssueSeverity;
use Relaticle\ImportWizard\Enums\IssueType;

final class ValueIssue extends Data
{
    /**
     * @param positive-int $affectedRows
     */
    public function __construct(
        public readonly string $value,
        public readonly string $message,
        public readonly int $affectedRows,
        public readonly IssueSeverity $severity,
        public readonly IssueType $type,
    ) {}

    public function isError(): bool
    {
        return $this->severity === IssueSeverity::ERROR;
    }

    public function isWarning(): bool
    {
        return $this->severity === IssueSeverity::WARNING;
    }
}
```

### Correction - Value Correction

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\ValueObjects;

final class Correction extends Data
{
    public function __construct(
        public readonly string $fieldName,
        public readonly string $originalValue,
        public readonly string $correctedValue,
        public readonly \DateTimeImmutable $createdAt,
    ) {}

    public function isSkip(): bool
    {
        return $this->correctedValue === '';
    }
}
```

### ValidationResult - Unified Validation Response

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\ValueObjects;

final class ValidationResult extends Data
{
    public function __construct(
        public readonly bool $isValid,
        public readonly ?ValueIssue $issue,
        public readonly ?string $parsedPreview,
    ) {}

    public static function valid(?string $preview = null): self
    {
        return new self(isValid: true, issue: null, parsedPreview: $preview);
    }

    public static function invalid(ValueIssue $issue): self
    {
        return new self(isValid: false, issue: $issue, parsedPreview: null);
    }
}
```

---

## Layer 2: Enums (Type-Safe Constants)

### ImportStep

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

enum ImportStep: int
{
    case UPLOAD = 1;
    case MAP = 2;
    case REVIEW = 3;
    case PREVIEW = 4;

    public function label(): string
    {
        return match ($this) {
            self::UPLOAD => 'Upload',
            self::MAP => 'Map Columns',
            self::REVIEW => 'Review Values',
            self::PREVIEW => 'Preview',
        };
    }

    public function canProceedTo(self $next): bool
    {
        return $next->value === $this->value + 1;
    }

    public function next(): ?self
    {
        return self::tryFrom($this->value + 1);
    }

    public function previous(): ?self
    {
        return self::tryFrom($this->value - 1);
    }
}
```

### IssueSeverity

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

enum IssueSeverity: string
{
    case ERROR = 'error';
    case WARNING = 'warning';

    public function icon(): string
    {
        return match ($this) {
            self::ERROR => 'heroicon-o-x-circle',
            self::WARNING => 'heroicon-o-exclamation-triangle',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ERROR => 'danger',
            self::WARNING => 'warning',
        };
    }
}
```

### IssueType

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

enum IssueType: string
{
    case INVALID_FORMAT = 'invalid_format';
    case INVALID_OPTION = 'invalid_option';
    case REQUIRED_BLANK = 'required_blank';
    case AMBIGUOUS_DATE = 'ambiguous_date';
    case INVALID_EMAIL = 'invalid_email';
    case INVALID_PHONE = 'invalid_phone';
    case INVALID_URL = 'invalid_url';
    case INVALID_ULID = 'invalid_ulid';
}
```

### MappingSource

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Enums;

enum MappingSource: string
{
    case AUTO = 'auto';        // Matched by column name
    case INFERRED = 'inferred'; // Detected from data patterns
    case MANUAL = 'manual';     // User selected
}
```

---

## Layer 3: Services (Business Logic)

### ImportSessionService - Session Management

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use Relaticle\ImportWizard\Infrastructure\ImportCache;
use Relaticle\ImportWizard\ValueObjects\ImportSession;

/**
 * Manages ImportSession lifecycle and persistence.
 * Single source of truth for session state.
 */
final readonly class ImportSessionService
{
    public function __construct(
        private ImportCache $cache,
    ) {}

    public function create(string $teamId, string $userId): ImportSession
    {
        $session = new ImportSession(
            id: (string) Str::uuid(),
            teamId: $teamId,
            userId: $userId,
            currentStep: ImportStep::UPLOAD,
            csvFile: null,
            mappings: new ColumnMappingCollection(),
            analyses: new ColumnAnalysisCollection(),
            corrections: new CorrectionCollection(),
            previewResult: null,
            createdAt: new \DateTimeImmutable(),
            lastActivityAt: new \DateTimeImmutable(),
        );

        $this->cache->putSession($session);

        return $session;
    }

    public function get(string $sessionId): ?ImportSession
    {
        return $this->cache->getSession($sessionId);
    }

    public function update(ImportSession $session): void
    {
        $this->cache->putSession($session);
    }

    public function delete(string $sessionId): void
    {
        $this->cache->forgetSession($sessionId);
    }

    public function touch(string $sessionId): void
    {
        $session = $this->get($sessionId);
        if ($session !== null) {
            $this->update($session->withLastActivity(new \DateTimeImmutable()));
        }
    }
}
```

### CsvService - File Operations

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Relaticle\ImportWizard\Infrastructure\ImportStorage;
use Relaticle\ImportWizard\ValueObjects\CsvFile;

/**
 * Handles all CSV file operations: parsing, validation, persistence.
 */
final readonly class CsvService
{
    public function __construct(
        private ImportStorage $storage,
        private CsvReaderFactory $readerFactory,
        private int $maxRows = 10_000,
        private int $maxSizeBytes = 50 * 1024 * 1024,
    ) {}

    /**
     * Parse and persist an uploaded file.
     *
     * @throws CsvParseException
     * @throws FileTooLargeException
     * @throws TooManyRowsException
     */
    public function parseAndPersist(
        TemporaryUploadedFile $upload,
        string $sessionId,
    ): CsvFile {
        $tempPath = $upload->getRealPath();
        $reader = $this->readerFactory->createFromPath($tempPath);

        $headers = $reader->getHeader();
        $this->validateHeaders($headers);

        $rowCount = iterator_count($reader->getRecords());
        $this->validateRowCount($rowCount);

        $persistedPath = $this->storage->persistTempFile($upload, $sessionId);

        return new CsvFile(
            path: $persistedPath,
            originalName: $upload->getClientOriginalName(),
            sizeBytes: $upload->getSize(),
            headers: $headers,
            rowCount: $rowCount,
            columnCount: count($headers),
        );
    }

    /**
     * @throws DuplicateHeaderException
     */
    private function validateHeaders(array $headers): void
    {
        $duplicates = array_diff_assoc($headers, array_unique($headers));
        if ($duplicates !== []) {
            throw new DuplicateHeaderException($duplicates);
        }
    }

    /**
     * @throws TooManyRowsException
     */
    private function validateRowCount(int $count): void
    {
        if ($count > $this->maxRows) {
            throw new TooManyRowsException($count, $this->maxRows);
        }
    }

    /**
     * Stream CSV records with optional offset/limit.
     *
     * @return \Generator<int, array<string, string>>
     */
    public function streamRecords(
        string $path,
        int $offset = 0,
        ?int $limit = null,
    ): \Generator {
        $reader = $this->readerFactory->createFromPath($path);
        $statement = Statement::create()->offset($offset);

        if ($limit !== null) {
            $statement = $statement->limit($limit);
        }

        yield from $statement->process($reader);
    }
}
```

### ColumnMappingService - Mapping Logic

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use Relaticle\ImportWizard\ValueObjects\ColumnMapping;
use Relaticle\ImportWizard\ValueObjects\ColumnMappingCollection;
use Relaticle\ImportWizard\ValueObjects\CsvFile;

/**
 * Handles column auto-mapping and type inference.
 */
final readonly class ColumnMappingService
{
    public function __construct(
        private DataTypeInferencer $typeInferencer,
        private CsvService $csvService,
    ) {}

    /**
     * Auto-map CSV columns to importer fields.
     *
     * @param class-string<BaseImporter> $importerClass
     */
    public function autoMap(
        CsvFile $csvFile,
        string $importerClass,
    ): ColumnMappingCollection {
        $importerColumns = $importerClass::getColumns();
        $relationshipFields = $importerClass::getRelationshipFields();

        $mappings = new ColumnMappingCollection();

        // Phase 1: Direct header matching
        foreach ($csvFile->headers as $csvColumn) {
            $mapping = $this->matchByHeader($csvColumn, $importerColumns);
            if ($mapping !== null) {
                $mappings->put($mapping->fieldName, $mapping);
            }
        }

        // Phase 2: Relationship matching
        foreach ($relationshipFields as $field) {
            $mapping = $this->matchRelationship($csvFile, $field, $mappings);
            if ($mapping !== null) {
                $mappings->put($mapping->fieldName, $mapping);
            }
        }

        // Phase 3: Data type inference for unmapped columns
        $unmappedColumns = array_diff($csvFile->headers, $mappings->pluck('csvColumn')->all());
        foreach ($unmappedColumns as $csvColumn) {
            $mapping = $this->inferMapping($csvFile, $csvColumn, $importerColumns, $mappings);
            if ($mapping !== null) {
                $mappings->put($mapping->fieldName, $mapping);
            }
        }

        return $mappings;
    }

    private function matchByHeader(
        string $csvColumn,
        array $importerColumns,
    ): ?ColumnMapping {
        $normalized = $this->normalizeForMatching($csvColumn);

        foreach ($importerColumns as $column) {
            foreach ($column->getGuesses() as $guess) {
                if ($this->normalizeForMatching($guess) === $normalized) {
                    return $this->createMapping($csvColumn, $column, MappingSource::AUTO);
                }
            }
        }

        return null;
    }

    private function normalizeForMatching(string $value): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $value));
    }
}
```

### ValueValidationService - THE Validation Service

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Enums\IssueSeverity;
use Relaticle\ImportWizard\Enums\IssueType;
use Relaticle\ImportWizard\Enums\TimestampFormat;
use Relaticle\ImportWizard\ValueObjects\ValidationResult;
use Relaticle\ImportWizard\ValueObjects\ValueIssue;

/**
 * SINGLE source of truth for all value validation.
 *
 * Used by:
 * - CsvAnalyzer (column analysis)
 * - ImportValuesController (API)
 * - ImportCorrectionsController (API)
 * - HasValueAnalysis (Livewire - format changes)
 */
final readonly class ValueValidationService
{
    /**
     * Validate a single value against field type and format.
     */
    public function validate(
        string $value,
        FieldDataType $fieldType,
        ?string $formatValue = null,
        int $affectedRows = 1,
    ): ValidationResult {
        if ($value === '') {
            return ValidationResult::valid();
        }

        return match ($fieldType) {
            FieldDataType::DATE => $this->validateDate($value, $formatValue, $affectedRows),
            FieldDataType::DATE_TIME => $this->validateTimestamp($value, $formatValue, $affectedRows),
            FieldDataType::SINGLE_CHOICE,
            FieldDataType::MULTI_CHOICE => ValidationResult::valid(), // Validated elsewhere with options
            default => ValidationResult::valid(),
        };
    }

    /**
     * Validate and return parsed preview for display.
     */
    public function validateWithPreview(
        string $value,
        FieldDataType $fieldType,
        ?string $formatValue = null,
    ): ValidationResult {
        $result = $this->validate($value, $fieldType, $formatValue);

        if (!$result->isValid || $value === '') {
            return $result;
        }

        $preview = $this->parsePreview($value, $fieldType, $formatValue);

        return new ValidationResult(
            isValid: true,
            issue: null,
            parsedPreview: $preview,
        );
    }

    private function validateDate(
        string $value,
        ?string $formatValue,
        int $affectedRows,
    ): ValidationResult {
        if ($formatValue === null) {
            return ValidationResult::valid();
        }

        $format = DateFormat::tryFrom($formatValue);
        if ($format === null) {
            return ValidationResult::valid();
        }

        $parsed = $format->parse($value);
        if ($parsed === null) {
            return ValidationResult::invalid(new ValueIssue(
                value: $value,
                message: "Cannot parse '{$value}' as {$format->getLabel()} date",
                affectedRows: $affectedRows,
                severity: IssueSeverity::ERROR,
                type: IssueType::INVALID_FORMAT,
            ));
        }

        // Check for ambiguity
        if ($format !== DateFormat::ISO && DateFormat::isAmbiguous($value)) {
            return new ValidationResult(
                isValid: true,
                issue: new ValueIssue(
                    value: $value,
                    message: "Date '{$value}' is ambiguous - confirm format",
                    affectedRows: $affectedRows,
                    severity: IssueSeverity::WARNING,
                    type: IssueType::AMBIGUOUS_DATE,
                ),
                parsedPreview: $parsed->format('Y-m-d'),
            );
        }

        return ValidationResult::valid($parsed->format('Y-m-d'));
    }

    private function validateTimestamp(
        string $value,
        ?string $formatValue,
        int $affectedRows,
    ): ValidationResult {
        if ($formatValue === null) {
            return ValidationResult::valid();
        }

        $format = TimestampFormat::tryFrom($formatValue);
        if ($format === null) {
            return ValidationResult::valid();
        }

        $parsed = $format->parse($value);
        if ($parsed === null) {
            return ValidationResult::invalid(new ValueIssue(
                value: $value,
                message: "Cannot parse '{$value}' as {$format->getLabel()} timestamp",
                affectedRows: $affectedRows,
                severity: IssueSeverity::ERROR,
                type: IssueType::INVALID_FORMAT,
            ));
        }

        return ValidationResult::valid($parsed->format('Y-m-d H:i:s'));
    }

    private function parsePreview(
        string $value,
        FieldDataType $fieldType,
        ?string $formatValue,
    ): ?string {
        if ($formatValue === null) {
            return null;
        }

        return match ($fieldType) {
            FieldDataType::DATE => DateFormat::tryFrom($formatValue)?->parse($value)?->format('Y-m-d'),
            FieldDataType::DATE_TIME => TimestampFormat::tryFrom($formatValue)?->parse($value)?->format('Y-m-d H:i:s'),
            default => null,
        };
    }
}
```

### ColumnAnalysisService - Column Analysis

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use Relaticle\ImportWizard\ValueObjects\ColumnAnalysis;
use Relaticle\ImportWizard\ValueObjects\ColumnAnalysisCollection;
use Relaticle\ImportWizard\ValueObjects\ColumnMappingCollection;

/**
 * Analyzes CSV columns for validation issues.
 * Single-pass CSV reading for performance.
 */
final readonly class ColumnAnalysisService
{
    public function __construct(
        private CsvService $csvService,
        private ValueValidationService $validationService,
        private DataTypeInferencer $typeInferencer,
    ) {}

    /**
     * Analyze all mapped columns in a single CSV pass.
     */
    public function analyze(
        string $csvPath,
        ColumnMappingCollection $mappings,
    ): ColumnAnalysisCollection {
        // Initialize collectors
        $collectors = [];
        foreach ($mappings as $mapping) {
            $collectors[$mapping->fieldName] = new ColumnAnalysisCollector($mapping);
        }

        // Single pass through CSV
        foreach ($this->csvService->streamRecords($csvPath) as $record) {
            foreach ($mappings as $mapping) {
                $value = $record[$mapping->csvColumn] ?? '';
                $collectors[$mapping->fieldName]->collect($value);
            }
        }

        // Build analysis results
        $analyses = new ColumnAnalysisCollection();
        foreach ($collectors as $fieldName => $collector) {
            $mapping = $mappings->get($fieldName);
            $analysis = $this->buildAnalysis($collector, $mapping);
            $analyses->put($fieldName, $analysis);
        }

        return $analyses;
    }

    private function buildAnalysis(
        ColumnAnalysisCollector $collector,
        ColumnMapping $mapping,
    ): ColumnAnalysis {
        $uniqueValues = $collector->getUniqueValues();
        $issues = new ValueIssueCollection();

        // Detect date format if applicable
        $detectedFormat = null;
        $formatConfidence = null;
        if ($mapping->isDateField()) {
            $detection = $this->typeInferencer->detectDateFormat(array_keys($uniqueValues));
            $detectedFormat = $detection->format;
            $formatConfidence = $detection->confidence;
        }

        // Validate each unique value
        foreach ($uniqueValues as $value => $count) {
            $result = $this->validationService->validate(
                value: (string) $value,
                fieldType: $mapping->fieldType,
                formatValue: $detectedFormat?->value,
                affectedRows: $count,
            );

            if ($result->issue !== null) {
                $issues->push($result->issue);
            }
        }

        return new ColumnAnalysis(
            csvColumn: $mapping->csvColumn,
            fieldName: $mapping->fieldName,
            uniqueValues: $uniqueValues,
            issues: $issues,
            totalRows: $collector->getTotalRows(),
            blankCount: $collector->getBlankCount(),
            detectedFormat: $detectedFormat,
            selectedFormat: null,
            formatConfidence: $formatConfidence,
        );
    }
}
```

---

## Layer 4: Infrastructure

### ImportCache - Typed Cache Access

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Infrastructure;

use Illuminate\Support\Facades\Cache;
use Relaticle\ImportWizard\ValueObjects\ColumnAnalysis;
use Relaticle\ImportWizard\ValueObjects\CorrectionCollection;
use Relaticle\ImportWizard\ValueObjects\ImportSession;

/**
 * Type-safe cache operations for import data.
 * Encapsulates all cache key management.
 */
final readonly class ImportCache
{
    private const TTL_HOURS = 24;

    // Session
    public function getSession(string $sessionId): ?ImportSession
    {
        return Cache::get($this->sessionKey($sessionId));
    }

    public function putSession(ImportSession $session): void
    {
        Cache::put(
            $this->sessionKey($session->id),
            $session,
            now()->addHours(self::TTL_HOURS),
        );
    }

    public function forgetSession(string $sessionId): void
    {
        Cache::forget($this->sessionKey($sessionId));
        // Also clean up related keys
        $this->forgetSessionData($sessionId);
    }

    // Analysis
    public function getAnalysis(string $sessionId, string $fieldName): ?ColumnAnalysis
    {
        return Cache::get($this->analysisKey($sessionId, $fieldName));
    }

    public function putAnalysis(string $sessionId, ColumnAnalysis $analysis): void
    {
        Cache::put(
            $this->analysisKey($sessionId, $analysis->fieldName),
            $analysis,
            now()->addHours(self::TTL_HOURS),
        );
    }

    // Unique Values (stored separately for memory efficiency)
    /**
     * @return array<string, int>
     */
    public function getUniqueValues(string $sessionId, string $csvColumn): array
    {
        return Cache::get($this->valuesKey($sessionId, $csvColumn), []);
    }

    /**
     * @param array<string, int> $values
     */
    public function putUniqueValues(string $sessionId, string $csvColumn, array $values): void
    {
        Cache::put(
            $this->valuesKey($sessionId, $csvColumn),
            $values,
            now()->addHours(self::TTL_HOURS),
        );
    }

    // Corrections
    public function getCorrections(string $sessionId, string $fieldName): CorrectionCollection
    {
        return Cache::get($this->correctionsKey($sessionId, $fieldName), new CorrectionCollection());
    }

    public function putCorrections(string $sessionId, string $fieldName, CorrectionCollection $corrections): void
    {
        Cache::put(
            $this->correctionsKey($sessionId, $fieldName),
            $corrections,
            now()->addHours(self::TTL_HOURS),
        );
    }

    // Key generation (single source of truth)
    private function sessionKey(string $sessionId): string
    {
        return "import:{$sessionId}:session";
    }

    private function analysisKey(string $sessionId, string $fieldName): string
    {
        return "import:{$sessionId}:analysis:{$fieldName}";
    }

    private function valuesKey(string $sessionId, string $csvColumn): string
    {
        return "import:{$sessionId}:values:{$csvColumn}";
    }

    private function correctionsKey(string $sessionId, string $fieldName): string
    {
        return "import:{$sessionId}:corrections:{$fieldName}";
    }

    private function forgetSessionData(string $sessionId): void
    {
        // Clean up all session-related cache keys
        // In production, use cache tags for easier cleanup
        Cache::forget($this->sessionKey($sessionId));
        // Note: Individual analysis/values/corrections cleaned via pattern or tags
    }
}
```

### ImportStorage - File Operations

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Infrastructure;

use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Handles all file storage operations for imports.
 */
final readonly class ImportStorage
{
    private const TEMP_DISK = 'local';
    private const TEMP_PREFIX = 'temp-imports';
    private const PERMANENT_PREFIX = 'imports';

    public function persistTempFile(TemporaryUploadedFile $file, string $sessionId): string
    {
        $path = self::TEMP_PREFIX . "/{$sessionId}/original.csv";
        Storage::disk(self::TEMP_DISK)->put($path, file_get_contents($file->getRealPath()));

        return Storage::disk(self::TEMP_DISK)->path($path);
    }

    public function moveToPermanent(string $tempPath, ?string $correctedContent = null): string
    {
        $permanentPath = self::PERMANENT_PREFIX . '/' . Str::uuid() . '.csv';

        if ($correctedContent !== null) {
            Storage::disk(self::TEMP_DISK)->put($permanentPath, $correctedContent);
        } else {
            Storage::disk(self::TEMP_DISK)->copy($tempPath, $permanentPath);
        }

        return $permanentPath;
    }

    public function deleteTempFiles(string $sessionId): void
    {
        Storage::disk(self::TEMP_DISK)->deleteDirectory(self::TEMP_PREFIX . "/{$sessionId}");
    }

    public function deletePermanentFile(string $path): void
    {
        Storage::disk(self::TEMP_DISK)->delete($path);
    }

    public function getTempPath(string $sessionId): string
    {
        return Storage::disk(self::TEMP_DISK)->path(self::TEMP_PREFIX . "/{$sessionId}/original.csv");
    }
}
```

---

## Layer 5: Presentation (Thin)

### ImportWizard Livewire Component (~200 lines)

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Relaticle\ImportWizard\Enums\ImportStep;
use Relaticle\ImportWizard\Services\ColumnAnalysisService;
use Relaticle\ImportWizard\Services\ColumnMappingService;
use Relaticle\ImportWizard\Services\CsvService;
use Relaticle\ImportWizard\Services\ImportExecutionService;
use Relaticle\ImportWizard\Services\ImportPreviewService;
use Relaticle\ImportWizard\Services\ImportSessionService;
use Relaticle\ImportWizard\ValueObjects\ImportSession;

/**
 * Thin orchestrator - delegates all business logic to services.
 */
final class ImportWizard extends Component implements HasActions
{
    use InteractsWithActions;
    use WithFileUploads;

    #[Locked]
    public string $entityType = 'companies';

    #[Locked]
    public ?string $returnUrl = null;

    public ?string $sessionId = null;

    public mixed $uploadedFile = null;

    // UI-only state
    public ?string $expandedColumn = null;

    public function boot(
        private ImportSessionService $sessionService,
        private CsvService $csvService,
        private ColumnMappingService $mappingService,
        private ColumnAnalysisService $analysisService,
        private ImportPreviewService $previewService,
        private ImportExecutionService $executionService,
    ): void {}

    public function mount(): void
    {
        $this->sessionId = $this->sessionService->create(
            teamId: Filament::getTenant()->getKey(),
            userId: auth()->id(),
        )->id;
    }

    #[Computed]
    public function session(): ?ImportSession
    {
        return $this->sessionId ? $this->sessionService->get($this->sessionId) : null;
    }

    public function updatedUploadedFile(): void
    {
        if ($this->uploadedFile === null) {
            return;
        }

        try {
            $csvFile = $this->csvService->parseAndPersist($this->uploadedFile, $this->sessionId);
            $session = $this->session->withCsvFile($csvFile);
            $this->sessionService->update($session);
        } catch (CsvParseException $e) {
            $this->addError('uploadedFile', $e->getMessage());
        }
    }

    public function nextStep(): void
    {
        $session = $this->session;
        $nextStep = $session->currentStep->next();

        if ($nextStep === null || !$this->canProceed()) {
            return;
        }

        // Step-specific preparation
        $session = match ($session->currentStep) {
            ImportStep::UPLOAD => $this->prepareMapping($session),
            ImportStep::MAP => $this->prepareReview($session),
            ImportStep::REVIEW => $this->preparePreview($session),
            default => $session,
        };

        $this->sessionService->update($session->withStep($nextStep));
    }

    public function previousStep(): void
    {
        $session = $this->session;
        $prevStep = $session->currentStep->previous();

        if ($prevStep !== null) {
            $this->sessionService->update($session->withStep($prevStep));
        }
    }

    private function canProceed(): bool
    {
        return match ($this->session->currentStep) {
            ImportStep::UPLOAD => $this->session->csvFile !== null,
            ImportStep::MAP => $this->session->mappings->hasAllRequiredMappings(),
            ImportStep::REVIEW => !$this->session->analyses->hasErrors(),
            ImportStep::PREVIEW => $this->session->previewResult?->hasRecords() ?? false,
        };
    }

    private function prepareMapping(ImportSession $session): ImportSession
    {
        $mappings = $this->mappingService->autoMap(
            $session->csvFile,
            $this->getImporterClass(),
        );

        return $session->withMappings($mappings);
    }

    private function prepareReview(ImportSession $session): ImportSession
    {
        $analyses = $this->analysisService->analyze(
            $session->csvFile->path,
            $session->mappings,
        );

        return $session->withAnalyses($analyses);
    }

    private function preparePreview(ImportSession $session): ImportSession
    {
        $result = $this->previewService->generate($session);

        return $session->withPreviewResult($result);
    }

    public function startImportAction(): Action
    {
        return Action::make('startImport')
            ->requiresConfirmation()
            ->action(fn () => $this->executeImport());
    }

    private function executeImport(): void
    {
        $this->executionService->execute($this->session, $this->getImporterClass());

        // Cleanup and redirect
        $this->sessionService->delete($this->sessionId);

        if ($this->returnUrl) {
            $this->redirect($this->returnUrl);
        }
    }

    // ... mapping/correction methods that delegate to services
}
```

### ImportValuesController (Thin)

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Relaticle\ImportWizard\Services\ImportSessionService;
use Relaticle\ImportWizard\Services\ValueValidationService;

/**
 * Thin controller - delegates to services.
 */
final class ImportValuesController
{
    public function __construct(
        private readonly ImportSessionService $sessionService,
        private readonly ValueValidationService $validationService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string'],
            'field_name' => ['required', 'string'],
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:500'],
            'errors_only' => ['boolean'],
        ]);

        $session = $this->sessionService->get($validated['session_id']);
        if ($session === null) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        $analysis = $session->analyses->get($validated['field_name']);
        if ($analysis === null) {
            return response()->json(['error' => 'Field not found'], 404);
        }

        $corrections = $session->corrections->forField($validated['field_name']);

        // Build paginated response with validation
        $values = $this->buildValueResponse(
            $analysis,
            $corrections,
            $validated['page'] ?? 1,
            $validated['per_page'] ?? 100,
            $validated['errors_only'] ?? false,
        );

        return response()->json($values);
    }

    private function buildValueResponse(
        ColumnAnalysis $analysis,
        CorrectionCollection $corrections,
        int $page,
        int $perPage,
        bool $errorsOnly,
    ): array {
        $uniqueValues = $analysis->uniqueValues;

        // Filter to errors only if requested
        if ($errorsOnly) {
            $errorValues = $analysis->issues->errors()->pluck('value')->all();
            $uniqueValues = array_filter(
                $uniqueValues,
                fn (int $count, string $value) => in_array($value, $errorValues, true),
                ARRAY_FILTER_USE_BOTH,
            );
        }

        // Paginate
        $total = count($uniqueValues);
        $paginatedValues = array_slice($uniqueValues, 0, $page * $perPage, preserve_keys: true);

        // Build response with validation for corrected values
        $values = [];
        foreach ($paginatedValues as $value => $count) {
            $stringValue = (string) $value;
            $correction = $corrections->get($stringValue);
            $valueToValidate = $correction?->correctedValue ?? $stringValue;
            $isSkipped = $correction?->isSkip() ?? false;

            $validationResult = $isSkipped
                ? ValidationResult::valid()
                : $this->validationService->validateWithPreview(
                    $valueToValidate,
                    $analysis->mapping->fieldType,
                    $analysis->effectiveFormat()?->value,
                );

            $values[] = [
                'value' => $stringValue,
                'count' => $count,
                'issue' => $validationResult->issue?->toArray(),
                'isSkipped' => $isSkipped,
                'correctedValue' => $correction?->correctedValue,
                'parsedPreview' => $validationResult->parsedPreview,
            ];
        }

        return [
            'values' => $values,
            'hasMore' => count($paginatedValues) < $total,
            'total' => $total,
            'showing' => count($paginatedValues),
        ];
    }
}
```

---

## Directory Structure

```
app-modules/ImportWizard/
├── config/
│   └── import-wizard.php
├── resources/views/
│   └── livewire/
│       ├── import-wizard.blade.php
│       └── partials/
├── routes/
│   └── web.php
└── src/
    ├── Enums/                              # Type-safe constants
    │   ├── ImportStep.php
    │   ├── DateFormat.php
    │   ├── TimestampFormat.php
    │   ├── IssueSeverity.php
    │   ├── IssueType.php
    │   ├── MappingSource.php
    │   └── DuplicateStrategy.php
    │
    ├── ValueObjects/                       # Immutable data structures
    │   ├── ImportSession.php
    │   ├── CsvFile.php
    │   ├── ColumnMapping.php
    │   ├── ColumnMappingCollection.php
    │   ├── ColumnAnalysis.php
    │   ├── ColumnAnalysisCollection.php
    │   ├── ValueIssue.php
    │   ├── ValueIssueCollection.php
    │   ├── Correction.php
    │   ├── CorrectionCollection.php
    │   ├── ValidationResult.php
    │   ├── ImportPreviewResult.php
    │   ├── RelationshipMapping.php
    │   └── RelationshipMatch.php
    │
    ├── Services/                           # Business logic
    │   ├── ImportSessionService.php
    │   ├── CsvService.php
    │   ├── ColumnMappingService.php
    │   ├── ColumnAnalysisService.php
    │   ├── ValueValidationService.php      # SINGLE validation source
    │   ├── ImportPreviewService.php
    │   ├── ImportExecutionService.php
    │   └── DataTypeInferencer.php
    │
    ├── Infrastructure/                     # External concerns
    │   ├── ImportCache.php                 # Typed cache access
    │   ├── ImportStorage.php               # File operations
    │   └── CsvReaderFactory.php
    │
    ├── Http/
    │   └── Controllers/
    │       ├── ImportValuesController.php
    │       ├── ImportCorrectionsController.php
    │       └── ImportPreviewController.php
    │
    ├── Livewire/
    │   └── ImportWizard.php                # ~200 lines, orchestration only
    │
    ├── Filament/
    │   ├── Imports/
    │   │   ├── BaseImporter.php
    │   │   ├── CompanyImporter.php
    │   │   ├── PeopleImporter.php
    │   │   ├── OpportunityImporter.php
    │   │   ├── TaskImporter.php
    │   │   └── NoteImporter.php
    │   └── Pages/
    │
    ├── Jobs/
    │   ├── StreamingImportJob.php
    │   ├── ProcessImportPreviewJob.php
    │   └── CleanupImportFilesJob.php
    │
    ├── Models/
    │   ├── Import.php
    │   └── FailedImportRow.php
    │
    └── Exceptions/
        ├── CsvParseException.php
        ├── DuplicateHeaderException.php
        ├── TooManyRowsException.php
        └── FileTooLargeException.php
```

---

## Key Design Decisions

### 1. ImportSession as Single Source of Truth

**Before:** State scattered across Livewire properties, cache keys, and traits.

**After:** One `ImportSession` value object holds everything. Persisted to cache. Livewire only holds `sessionId`.

```php
// Everything accessible from session
$session = $this->sessionService->get($sessionId);
$session->csvFile;
$session->mappings;
$session->analyses;
$session->corrections;
$session->currentStep;
```

### 2. ValueValidationService - No Duplication

**Before:** Validation logic in 4+ files.

**After:** One service, used everywhere.

```php
// In ColumnAnalysisService
$result = $this->validationService->validate($value, $fieldType, $format);

// In ImportValuesController
$result = $this->validationService->validateWithPreview($value, $fieldType, $format);

// In ImportCorrectionsController
$result = $this->validationService->validate($correctedValue, $fieldType, $format);
```

### 3. Thin Livewire, Fat Services

**Before:** 898-line Livewire component with 4 traits.

**After:** ~200-line component that only orchestrates.

```php
// Livewire just delegates
private function prepareMapping(ImportSession $session): ImportSession
{
    $mappings = $this->mappingService->autoMap($session->csvFile, $this->getImporterClass());
    return $session->withMappings($mappings);
}
```

### 4. Typed Collections

**Before:** `array<string, mixed>` everywhere.

**After:** Typed collection classes with domain methods.

```php
// Type-safe, with useful methods
$mappings->hasAllRequiredMappings();
$mappings->getRequiredFields();
$mappings->getMappingForField('name');

$analyses->hasErrors();
$analyses->totalErrorCount();
```

### 5. Immutable Value Objects

**Before:** Mutable arrays and objects.

**After:** Immutable value objects with `with*` methods.

```php
// Immutable updates
$session = $session
    ->withStep(ImportStep::MAP)
    ->withMappings($mappings);
```

---

## Migration Path

### Phase 1: Extract Services (Keep Existing Working)
1. Create `ValueValidationService` - extract and consolidate validation
2. Create `ImportCache` - centralize cache key management
3. Create `ImportStorage` - centralize file operations
4. Update existing code to use services (no behavior change)

### Phase 2: Create Value Objects
1. Create `ImportSession` value object
2. Create typed collections (`ColumnMappingCollection`, etc.)
3. Migrate state from Livewire properties to `ImportSession`
4. Update services to use value objects

### Phase 3: Simplify Livewire
1. Remove traits, delegate to services
2. Slim down to ~200 lines
3. Update views to work with new structure

### Phase 4: Update API Controllers
1. Refactor to use shared services
2. Remove duplicate logic
3. Add proper typing

### Phase 5: Cleanup
1. Remove old traits
2. Remove old data classes
3. Update tests
4. Document

---

## Benefits

| Aspect | Current | New |
|--------|---------|-----|
| **Lines of Code** | ~8,700 | ~5,500 (estimated) |
| **Type Safety** | Partial (`mixed` common) | Full (PHPStan level 9) |
| **Duplication** | ~15% | ~0% |
| **Testability** | Hard (coupled traits) | Easy (isolated services) |
| **Maintainability** | Medium (scattered logic) | High (clear boundaries) |
| **Cache Key Management** | 6+ hardcoded places | 1 class |
| **Validation Logic** | 4+ implementations | 1 service |

---

## Alpine.js Integration

The frontend uses a single Alpine.js component for the Review step, communicating with Laravel via JSON APIs to avoid Livewire payload limits.

### ValueReviewer Alpine Component

```javascript
// resources/js/components/value-reviewer.js

export default function valueReviewer(config) {
    return {
        // State
        sessionId: config.sessionId,
        fieldName: config.fieldName,
        csvColumn: config.csvColumn,

        values: [],
        loading: false,
        page: 1,
        hasMore: false,
        total: 0,
        errorsOnly: false,

        // Lifecycle
        init() {
            this.loadValues();
            this.$watch('errorsOnly', () => this.refresh());
        },

        // Actions
        async loadValues() {
            if (this.loading) return;
            this.loading = true;

            try {
                const response = await fetch('/app/import/values', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        session_id: this.sessionId,
                        field_name: this.fieldName,
                        page: this.page,
                        errors_only: this.errorsOnly,
                    }),
                });

                const data = await response.json();
                this.values = data.values;
                this.hasMore = data.hasMore;
                this.total = data.total;
            } catch (error) {
                console.error('Failed to load values:', error);
            } finally {
                this.loading = false;
            }
        },

        async saveCorrection(originalValue, newValue) {
            const response = await fetch('/app/import/corrections', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({
                    session_id: this.sessionId,
                    field_name: this.fieldName,
                    old_value: originalValue,
                    new_value: newValue,
                }),
            });

            if (response.ok) {
                const data = await response.json();
                this.updateValueInList(originalValue, {
                    correctedValue: newValue,
                    isSkipped: data.isSkipped,
                    issue: data.issue,
                });

                // Notify Livewire of count changes
                this.$wire.syncCorrectionCounts();
            }
        },

        async skipValue(originalValue) {
            await this.saveCorrection(originalValue, '');
        },

        async unskipValue(originalValue) {
            const response = await fetch('/app/import/corrections', {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({
                    session_id: this.sessionId,
                    field_name: this.fieldName,
                    old_value: originalValue,
                }),
            });

            if (response.ok) {
                this.updateValueInList(originalValue, {
                    correctedValue: null,
                    isSkipped: false,
                });
                this.$wire.syncCorrectionCounts();
            }
        },

        updateValueInList(originalValue, updates) {
            const index = this.values.findIndex(v => v.value === originalValue);
            if (index !== -1) {
                this.values[index] = { ...this.values[index], ...updates };
            }
        },

        refresh() {
            this.page = 1;
            this.loadValues();
        },

        loadMore() {
            this.page++;
            this.loadValues();
        },
    };
}
```

### Blade Integration

```blade
{{-- resources/views/livewire/partials/step-review.blade.php --}}

<div class="flex h-full">
    {{-- Column Sidebar --}}
    <div class="w-64 border-r">
        @foreach($this->analysisColumns as $analysis)
            <button
                wire:click="selectColumn('{{ $analysis->fieldName }}')"
                @class([
                    'w-full px-4 py-2 text-left',
                    'bg-primary-50' => $expandedColumn === $analysis->fieldName,
                ])
            >
                <span>{{ $analysis->fieldLabel }}</span>
                @if($analysis->errorCount() > 0)
                    <span class="text-danger-500">{{ $analysis->errorCount() }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Value Reviewer Panel --}}
    @if($expandedColumn)
        @php $analysis = $this->getAnalysis($expandedColumn); @endphp

        <div
            class="flex-1"
            x-data="valueReviewer({
                sessionId: @js($sessionId),
                fieldName: @js($expandedColumn),
                csvColumn: @js($analysis->csvColumn),
            })"
        >
            {{-- Filter toggle --}}
            <div class="p-4 border-b">
                <label class="flex items-center gap-2">
                    <input type="checkbox" x-model="errorsOnly">
                    Show errors only
                </label>
                <span class="text-sm text-gray-500">
                    Showing <span x-text="values.length"></span> of <span x-text="total"></span> values
                </span>
            </div>

            {{-- Values list --}}
            <div class="divide-y">
                <template x-for="item in values" :key="item.value">
                    <div class="p-4">
                        {{-- Value display and correction UI --}}
                        @include('import-wizard::partials.value-row')
                    </div>
                </template>
            </div>

            {{-- Load more --}}
            <div x-show="hasMore" class="p-4">
                <button @click="loadMore" x-bind:disabled="loading">
                    <span x-show="!loading">Load More</span>
                    <span x-show="loading">Loading...</span>
                </button>
            </div>
        </div>
    @endif
</div>
```

---

## Exception Handling

### Custom Exceptions

```php
<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Exceptions;

use Exception;

/**
 * Base exception for all ImportWizard errors.
 */
abstract class ImportWizardException extends Exception
{
    abstract public function getUserMessage(): string;
}

final class CsvParseException extends ImportWizardException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $filePath = null,
    ) {
        parent::__construct("CSV parse error: {$reason}");
    }

    public function getUserMessage(): string
    {
        return match ($this->reason) {
            'encoding' => 'File encoding not supported. Please save as UTF-8.',
            'malformed' => 'CSV file is malformed. Check for unclosed quotes.',
            default => "Unable to parse CSV file: {$this->reason}",
        };
    }
}

final class DuplicateHeaderException extends ImportWizardException
{
    /**
     * @param list<string> $duplicates
     */
    public function __construct(
        public readonly array $duplicates,
    ) {
        $list = implode(', ', $duplicates);
        parent::__construct("Duplicate column headers: {$list}");
    }

    public function getUserMessage(): string
    {
        return 'Column names must be unique. Found duplicates: ' . implode(', ', $this->duplicates);
    }
}

final class TooManyRowsException extends ImportWizardException
{
    public function __construct(
        public readonly int $actual,
        public readonly int $max,
    ) {
        parent::__construct("Too many rows: {$actual} (max: {$max})");
    }

    public function getUserMessage(): string
    {
        return "File has {$this->actual} rows. Maximum allowed is {$this->max}. Please split into smaller files.";
    }
}

final class FileTooLargeException extends ImportWizardException
{
    public function __construct(
        public readonly int $actualBytes,
        public readonly int $maxBytes,
    ) {
        parent::__construct("File too large: {$actualBytes} bytes (max: {$maxBytes})");
    }

    public function getUserMessage(): string
    {
        $actualMb = round($this->actualBytes / 1024 / 1024, 1);
        $maxMb = round($this->maxBytes / 1024 / 1024, 1);
        return "File is {$actualMb}MB. Maximum allowed is {$maxMb}MB.";
    }
}

final class SessionExpiredException extends ImportWizardException
{
    public function __construct(
        public readonly string $sessionId,
    ) {
        parent::__construct("Import session expired: {$sessionId}");
    }

    public function getUserMessage(): string
    {
        return 'Your import session has expired. Please start a new import.';
    }
}

final class InvalidMappingException extends ImportWizardException
{
    public function __construct(
        public readonly string $fieldName,
        public readonly string $reason,
    ) {
        parent::__construct("Invalid mapping for {$fieldName}: {$reason}");
    }

    public function getUserMessage(): string
    {
        return "Cannot map {$this->fieldName}: {$this->reason}";
    }
}
```

### Error Handling in Livewire

```php
// In ImportWizard.php

public function updatedUploadedFile(): void
{
    if ($this->uploadedFile === null) {
        return;
    }

    try {
        $csvFile = $this->csvService->parseAndPersist($this->uploadedFile, $this->sessionId);
        $session = $this->session->withCsvFile($csvFile);
        $this->sessionService->update($session);
    } catch (ImportWizardException $e) {
        // Domain exceptions - show user-friendly message
        $this->addError('uploadedFile', $e->getUserMessage());
    } catch (\Throwable $e) {
        // Unexpected errors - log and show generic message
        report($e);
        $this->addError('uploadedFile', 'An unexpected error occurred. Please try again.');
    }
}
```

### Error Handling in Controllers

```php
// In ImportValuesController.php

public function __invoke(Request $request): JsonResponse
{
    try {
        $validated = $request->validate([/* rules */]);

        $session = $this->sessionService->get($validated['session_id']);
        if ($session === null) {
            throw new SessionExpiredException($validated['session_id']);
        }

        // ... process request

    } catch (ValidationException $e) {
        return response()->json([
            'error' => 'Validation failed',
            'errors' => $e->errors(),
        ], 422);
    } catch (SessionExpiredException $e) {
        return response()->json([
            'error' => $e->getUserMessage(),
            'code' => 'session_expired',
        ], 410); // Gone
    } catch (ImportWizardException $e) {
        return response()->json([
            'error' => $e->getUserMessage(),
        ], 400);
    } catch (\Throwable $e) {
        report($e);
        return response()->json([
            'error' => 'An unexpected error occurred',
        ], 500);
    }
}
```

---

## Testing Strategy

### Unit Tests (Services)

```php
<?php

declare(strict_types=1);

use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\ImportWizard\Enums\DateFormat;
use Relaticle\ImportWizard\Services\ValueValidationService;

describe('ValueValidationService', function () {
    beforeEach(function () {
        $this->service = new ValueValidationService();
    });

    it('validates ISO dates correctly', function () {
        $result = $this->service->validate(
            value: '2024-05-15',
            fieldType: FieldDataType::DATE,
            formatValue: DateFormat::ISO->value,
        );

        expect($result->isValid)->toBeTrue();
        expect($result->parsedPreview)->toBe('2024-05-15');
    });

    it('rejects invalid date formats', function () {
        $result = $this->service->validate(
            value: 'not-a-date',
            fieldType: FieldDataType::DATE,
            formatValue: DateFormat::ISO->value,
        );

        expect($result->isValid)->toBeFalse();
        expect($result->issue)->not->toBeNull();
        expect($result->issue->type)->toBe(IssueType::INVALID_FORMAT);
    });

    it('warns about ambiguous dates', function () {
        $result = $this->service->validate(
            value: '05/06/2024',
            fieldType: FieldDataType::DATE,
            formatValue: DateFormat::AMERICAN->value,
        );

        expect($result->isValid)->toBeTrue();
        expect($result->issue)->not->toBeNull();
        expect($result->issue->severity)->toBe(IssueSeverity::WARNING);
    });

    it('returns preview for valid dates', function () {
        $result = $this->service->validateWithPreview(
            value: '15/05/2024',
            fieldType: FieldDataType::DATE,
            formatValue: DateFormat::EUROPEAN->value,
        );

        expect($result->isValid)->toBeTrue();
        expect($result->parsedPreview)->toBe('2024-05-15');
    });
});
```

### Unit Tests (Value Objects)

```php
<?php

declare(strict_types=1);

use Relaticle\ImportWizard\Enums\ImportStep;
use Relaticle\ImportWizard\ValueObjects\ImportSession;
use Relaticle\ImportWizard\ValueObjects\ColumnMappingCollection;

describe('ImportSession', function () {
    it('creates with default state', function () {
        $session = new ImportSession(
            id: 'test-id',
            teamId: 'team-1',
            userId: 'user-1',
            currentStep: ImportStep::UPLOAD,
            csvFile: null,
            mappings: new ColumnMappingCollection(),
            analyses: new ColumnAnalysisCollection(),
            corrections: new CorrectionCollection(),
            previewResult: null,
            createdAt: new DateTimeImmutable(),
            lastActivityAt: new DateTimeImmutable(),
        );

        expect($session->id)->toBe('test-id');
        expect($session->currentStep)->toBe(ImportStep::UPLOAD);
        expect($session->csvFile)->toBeNull();
    });

    it('creates new instance with updated step', function () {
        $session = createTestSession();
        $updated = $session->withStep(ImportStep::MAP);

        expect($session->currentStep)->toBe(ImportStep::UPLOAD); // Original unchanged
        expect($updated->currentStep)->toBe(ImportStep::MAP);
        expect($updated->id)->toBe($session->id); // Same ID
    });
});

describe('ColumnMappingCollection', function () {
    it('detects missing required mappings', function () {
        $mappings = new ColumnMappingCollection([
            createMapping('name', isRequired: true, csvColumn: 'Name'),
            createMapping('email', isRequired: true, csvColumn: ''), // Not mapped
        ]);

        expect($mappings->hasAllRequiredMappings())->toBeFalse();
        expect($mappings->getUnmappedRequired())->toHaveCount(1);
    });

    it('finds mapping by csv column', function () {
        $mappings = new ColumnMappingCollection([
            createMapping('name', csvColumn: 'Company Name'),
        ]);

        $found = $mappings->getMappingForCsvColumn('Company Name');
        expect($found)->not->toBeNull();
        expect($found->fieldName)->toBe('name');
    });
});
```

### Integration Tests (Services with Cache)

```php
<?php

declare(strict_types=1);

use Relaticle\ImportWizard\Services\ImportSessionService;
use Relaticle\ImportWizard\Infrastructure\ImportCache;

describe('ImportSessionService', function () {
    beforeEach(function () {
        $this->cache = new ImportCache();
        $this->service = new ImportSessionService($this->cache);
    });

    it('creates and retrieves session', function () {
        $session = $this->service->create(
            teamId: 'team-1',
            userId: 'user-1',
        );

        $retrieved = $this->service->get($session->id);

        expect($retrieved)->not->toBeNull();
        expect($retrieved->id)->toBe($session->id);
        expect($retrieved->teamId)->toBe('team-1');
    });

    it('updates session state', function () {
        $session = $this->service->create('team-1', 'user-1');
        $updated = $session->withStep(ImportStep::MAP);

        $this->service->update($updated);

        $retrieved = $this->service->get($session->id);
        expect($retrieved->currentStep)->toBe(ImportStep::MAP);
    });

    it('deletes session completely', function () {
        $session = $this->service->create('team-1', 'user-1');

        $this->service->delete($session->id);

        expect($this->service->get($session->id))->toBeNull();
    });
});
```

### Feature Tests (Livewire)

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Relaticle\ImportWizard\Livewire\ImportWizard;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->personalTeam());
});

describe('ImportWizard', function () {
    it('starts at upload step', function () {
        livewire(ImportWizard::class, ['entityType' => 'companies'])
            ->assertSet('session.currentStep', ImportStep::UPLOAD)
            ->assertSee('Upload your CSV file');
    });

    it('validates file upload', function () {
        livewire(ImportWizard::class, ['entityType' => 'companies'])
            ->set('uploadedFile', createTestCsv(['Name', 'Email'], [
                ['Acme Corp', 'info@acme.com'],
            ]))
            ->assertHasNoErrors()
            ->assertSet('session.csvFile.rowCount', 1);
    });

    it('rejects duplicate column headers', function () {
        livewire(ImportWizard::class, ['entityType' => 'companies'])
            ->set('uploadedFile', createTestCsv(['Name', 'Name'], [
                ['A', 'B'],
            ]))
            ->assertHasErrors(['uploadedFile' => 'Column names must be unique']);
    });

    it('advances through steps', function () {
        livewire(ImportWizard::class, ['entityType' => 'companies'])
            ->set('uploadedFile', createValidTestCsv())
            ->call('nextStep')
            ->assertSet('session.currentStep', ImportStep::MAP)
            ->call('nextStep')
            ->assertSet('session.currentStep', ImportStep::REVIEW);
    });
});
```

### API Tests

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->personalTeam());

    $this->session = createImportSessionWithValues();
});

describe('ImportValuesController', function () {
    it('returns paginated values', function () {
        $response = $this->postJson('/app/import/values', [
            'session_id' => $this->session->id,
            'field_name' => 'name',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'values' => [['value', 'count', 'issue', 'isSkipped', 'correctedValue']],
                'hasMore',
                'total',
                'showing',
            ]);
    });

    it('filters to errors only', function () {
        $response = $this->postJson('/app/import/values', [
            'session_id' => $this->session->id,
            'field_name' => 'email',
            'errors_only' => true,
        ]);

        $response->assertOk();

        $values = $response->json('values');
        foreach ($values as $value) {
            expect($value['issue'])->not->toBeNull();
            expect($value['issue']['severity'])->toBe('error');
        }
    });

    it('returns 410 for expired session', function () {
        $this->postJson('/app/import/values', [
            'session_id' => 'non-existent-session',
            'field_name' => 'name',
        ])
            ->assertStatus(410)
            ->assertJson(['code' => 'session_expired']);
    });
});

describe('ImportCorrectionsController', function () {
    it('stores correction', function () {
        $response = $this->postJson('/app/import/corrections', [
            'session_id' => $this->session->id,
            'field_name' => 'email',
            'old_value' => 'invalid',
            'new_value' => 'valid@example.com',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'isSkipped' => false,
            ]);
    });

    it('marks value as skipped when empty', function () {
        $response = $this->postJson('/app/import/corrections', [
            'session_id' => $this->session->id,
            'field_name' => 'email',
            'old_value' => 'invalid',
            'new_value' => '',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'isSkipped' => true,
            ]);
    });

    it('removes correction', function () {
        // First create a correction
        $this->postJson('/app/import/corrections', [
            'session_id' => $this->session->id,
            'field_name' => 'email',
            'old_value' => 'invalid',
            'new_value' => 'valid@example.com',
        ]);

        // Then remove it
        $response = $this->deleteJson('/app/import/corrections', [
            'session_id' => $this->session->id,
            'field_name' => 'email',
            'old_value' => 'invalid',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true]);
    });
});
```

### Test Helpers

```php
<?php

// tests/Pest.php or tests/Helpers.php

use Illuminate\Http\UploadedFile;
use Relaticle\ImportWizard\ValueObjects\ColumnMapping;
use Relaticle\ImportWizard\ValueObjects\ImportSession;

function createTestCsv(array $headers, array $rows): UploadedFile
{
    $content = implode(',', $headers) . "\n";
    foreach ($rows as $row) {
        $content .= implode(',', $row) . "\n";
    }

    return UploadedFile::fake()->createWithContent('test.csv', $content);
}

function createValidTestCsv(): UploadedFile
{
    return createTestCsv(
        ['Name', 'Email', 'Phone'],
        [
            ['Acme Corp', 'info@acme.com', '555-1234'],
            ['Beta Inc', 'hello@beta.io', '555-5678'],
        ]
    );
}

function createTestSession(): ImportSession
{
    return new ImportSession(
        id: (string) Str::uuid(),
        teamId: 'team-1',
        userId: 'user-1',
        currentStep: ImportStep::UPLOAD,
        csvFile: null,
        mappings: new ColumnMappingCollection(),
        analyses: new ColumnAnalysisCollection(),
        corrections: new CorrectionCollection(),
        previewResult: null,
        createdAt: new DateTimeImmutable(),
        lastActivityAt: new DateTimeImmutable(),
    );
}

function createMapping(
    string $fieldName,
    bool $isRequired = false,
    string $csvColumn = '',
): ColumnMapping {
    return new ColumnMapping(
        csvColumn: $csvColumn,
        fieldName: $fieldName,
        fieldLabel: ucfirst($fieldName),
        fieldType: FieldDataType::TEXT,
        isRequired: $isRequired,
        isCustomField: false,
        isRelationship: false,
        relationshipMapping: null,
        source: MappingSource::MANUAL,
    );
}

function createImportSessionWithValues(): ImportSession
{
    $session = createTestSession();
    $sessionService = app(ImportSessionService::class);

    // Set up session with values for testing
    $session = $session
        ->withCsvFile(new CsvFile(/* ... */))
        ->withMappings(new ColumnMappingCollection([/* ... */]))
        ->withAnalyses(new ColumnAnalysisCollection([/* ... */]));

    $sessionService->update($session);

    return $session;
}
```

---

## Summary

This architecture follows Taylor's doctrine:

1. **Simple** - Clear layers, single responsibilities
2. **Disposable** - Each service replaceable independently
3. **Easy to change** - Modify one place, not four
4. **Not clever** - Obvious patterns, no magic

The key insight: **Move complexity from Livewire (UI) to Services (logic)**.

Livewire should only:
- Handle user events
- Call services
- Update UI

Services should:
- Contain business logic
- Be stateless
- Be easily testable
- Have single responsibility

---

## Implementation Checklist

### Phase 1: Foundation (Services & Infrastructure)
- [ ] Create `ValueValidationService` - extract all validation logic
- [ ] Create `ImportCache` - centralize cache key management
- [ ] Create `ImportStorage` - centralize file operations
- [ ] Create custom exceptions
- [ ] Write unit tests for services

### Phase 2: Value Objects
- [ ] Create `ImportSession` with `with*` methods
- [ ] Create `CsvFile` value object
- [ ] Create `ColumnMapping` and `ColumnMappingCollection`
- [ ] Create `ColumnAnalysis` and `ColumnAnalysisCollection`
- [ ] Create `ValueIssue` and `ValueIssueCollection`
- [ ] Create `Correction` and `CorrectionCollection`
- [ ] Create `ValidationResult` value object
- [ ] Write unit tests for value objects

### Phase 3: Application Services
- [ ] Create `ImportSessionService`
- [ ] Create `CsvService`
- [ ] Create `ColumnMappingService`
- [ ] Create `ColumnAnalysisService`
- [ ] Create `ImportPreviewService`
- [ ] Create `ImportExecutionService`
- [ ] Write integration tests

### Phase 4: Presentation Layer
- [ ] Refactor `ImportWizard` Livewire (~200 lines)
- [ ] Refactor `ImportValuesController`
- [ ] Refactor `ImportCorrectionsController`
- [ ] Update Blade views
- [ ] Update Alpine.js component
- [ ] Write Livewire and API tests

### Phase 5: Cleanup
- [ ] Remove old traits
- [ ] Remove deprecated code
- [ ] Run full test suite
- [ ] Update documentation
- [ ] Performance testing
