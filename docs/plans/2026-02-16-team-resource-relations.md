# Team Resource Relation Managers & Dashboard Links — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add read-only relation managers to TeamResource in the sysadmin panel and link team names in the dashboard widget to the team view page.

**Architecture:** Create a ViewTeam page with 6 relation managers (members, companies, people, tasks, opportunities, notes). All relation managers are read-only — no create/edit/delete actions. Update TopTeamsTableWidget to link team names to the view page.

**Tech Stack:** Laravel 12, Filament 4, PHP 8.4

---

### Task 1: Create ViewTeam Page

**Files:**
- Create: `app-modules/SystemAdmin/src/Filament/Resources/TeamResource/Pages/ViewTeam.php`
- Modify: `app-modules/SystemAdmin/src/Filament/Resources/TeamResource.php:120-128`

**Step 1: Create the ViewTeam page**

Follow the exact pattern from `app-modules/SystemAdmin/src/Filament/Resources/UserResource/Pages/ViewUser.php`.

```php
<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Override;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource;

final class ViewTeam extends ViewRecord
{
    protected static string $resource = TeamResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
```

**Step 2: Register ViewTeam page and relation managers in TeamResource**

In `TeamResource.php`, add import for `ViewTeam` and update `getPages()` and `getRelations()`:

Add import:
```php
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\Pages\ViewTeam;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers\MembersRelationManager;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers\CompaniesRelationManager;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers\PeopleRelationManager;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers\TasksRelationManager;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers\OpportunitiesRelationManager;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers\NotesRelationManager;
```

Update `getRelations()`:
```php
public static function getRelations(): array
{
    return [
        MembersRelationManager::class,
        CompaniesRelationManager::class,
        PeopleRelationManager::class,
        TasksRelationManager::class,
        OpportunitiesRelationManager::class,
        NotesRelationManager::class,
    ];
}
```

Update `getPages()`:
```php
public static function getPages(): array
{
    return [
        'index' => ListTeams::route('/'),
        'create' => CreateTeam::route('/create'),
        'view' => ViewTeam::route('/{record}'),
        'edit' => EditTeam::route('/{record}/edit'),
    ];
}
```

**Step 3: Verify the view page loads**

Run: `php artisan route:list --path=sysadmin/teams`
Expected: Should show routes including `sysadmin/teams/{record}` for the view page.

**Step 4: Commit**

```bash
git add app-modules/SystemAdmin/src/Filament/Resources/TeamResource.php \
       app-modules/SystemAdmin/src/Filament/Resources/TeamResource/Pages/ViewTeam.php
git commit -m "feat: add ViewTeam page to sysadmin TeamResource"
```

---

### Task 2: Create MembersRelationManager

**Files:**
- Create: `app-modules/SystemAdmin/src/Filament/Resources/TeamResource/RelationManagers/MembersRelationManager.php`

**Step 1: Create the relation manager**

The Team model's `users()` relationship is a BelongsToMany from Jetstream. This is the only non-HasMany relationship, so use `$relationship = 'users'`.

```php
<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Members';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-users';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('name');
    }
}
```

No form needed — this is read-only.

**Step 2: Commit**

```bash
git add app-modules/SystemAdmin/src/Filament/Resources/TeamResource/RelationManagers/MembersRelationManager.php
git commit -m "feat: add MembersRelationManager to sysadmin TeamResource"
```

---

### Task 3: Create CompaniesRelationManager

**Files:**
- Create: `app-modules/SystemAdmin/src/Filament/Resources/TeamResource/RelationManagers/CompaniesRelationManager.php`

**Step 1: Create the relation manager**

Columns match sysadmin `CompanyResource` table but without the team column (redundant when viewing from team context).

```php
<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers;

use App\Enums\CreationSource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CompaniesRelationManager extends RelationManager
{
    protected static string $relationship = 'companies';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-building-office';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->sortable(),
                TextColumn::make('creation_source')
                    ->badge()
                    ->color(fn (CreationSource $state): string => match ($state) {
                        CreationSource::WEB => 'info',
                        CreationSource::SYSTEM => 'warning',
                        CreationSource::IMPORT => 'success',
                    })
                    ->label('Source'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
```

**Step 2: Commit**

```bash
git add app-modules/SystemAdmin/src/Filament/Resources/TeamResource/RelationManagers/CompaniesRelationManager.php
git commit -m "feat: add CompaniesRelationManager to sysadmin TeamResource"
```

---

### Task 4: Create PeopleRelationManager

**Files:**
- Create: `app-modules/SystemAdmin/src/Filament/Resources/TeamResource/RelationManagers/PeopleRelationManager.php`

**Step 1: Create the relation manager**

```php
<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers;

use App\Enums\CreationSource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class PeopleRelationManager extends RelationManager
{
    protected static string $relationship = 'people';

    protected static ?string $modelLabel = 'person';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-user';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->sortable(),
                TextColumn::make('creation_source')
                    ->badge()
                    ->color(fn (CreationSource $state): string => match ($state) {
                        CreationSource::WEB => 'info',
                        CreationSource::SYSTEM => 'warning',
                        CreationSource::IMPORT => 'success',
                    })
                    ->label('Source'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
```

**Step 2: Commit**

```bash
git add app-modules/SystemAdmin/src/Filament/Resources/TeamResource/RelationManagers/PeopleRelationManager.php
git commit -m "feat: add PeopleRelationManager to sysadmin TeamResource"
```

---

### Task 5: Create TasksRelationManager

**Files:**
- Create: `app-modules/SystemAdmin/src/Filament/Resources/TeamResource/RelationManagers/TasksRelationManager.php`

**Step 1: Create the relation manager**

```php
<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers;

use App\Enums\CreationSource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-check-circle';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->sortable(),
                TextColumn::make('creation_source')
                    ->badge()
                    ->color(fn (CreationSource $state): string => match ($state) {
                        CreationSource::WEB => 'info',
                        CreationSource::SYSTEM => 'warning',
                        CreationSource::IMPORT => 'success',
                    })
                    ->label('Source'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
```

**Step 2: Commit**

```bash
git add app-modules/SystemAdmin/src/Filament/Resources/TeamResource/RelationManagers/TasksRelationManager.php
git commit -m "feat: add TasksRelationManager to sysadmin TeamResource"
```

---

### Task 6: Create OpportunitiesRelationManager

**Files:**
- Create: `app-modules/SystemAdmin/src/Filament/Resources/TeamResource/RelationManagers/OpportunitiesRelationManager.php`

**Step 1: Create the relation manager**

```php
<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers;

use App\Enums\CreationSource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class OpportunitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'opportunities';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-currency-dollar';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('contact.name')
                    ->label('Contact')
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->sortable(),
                TextColumn::make('creation_source')
                    ->badge()
                    ->color(fn (CreationSource $state): string => match ($state) {
                        CreationSource::WEB => 'info',
                        CreationSource::SYSTEM => 'warning',
                        CreationSource::IMPORT => 'success',
                    })
                    ->label('Source'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
```

**Step 2: Commit**

```bash
git add app-modules/SystemAdmin/src/Filament/Resources/TeamResource/RelationManagers/OpportunitiesRelationManager.php
git commit -m "feat: add OpportunitiesRelationManager to sysadmin TeamResource"
```

---

### Task 7: Create NotesRelationManager

**Files:**
- Create: `app-modules/SystemAdmin/src/Filament/Resources/TeamResource/RelationManagers/NotesRelationManager.php`

**Step 1: Create the relation manager**

```php
<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers;

use App\Enums\CreationSource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class NotesRelationManager extends RelationManager
{
    protected static string $relationship = 'notes';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-document-text';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->sortable(),
                TextColumn::make('creation_source')
                    ->badge()
                    ->color(fn (CreationSource $state): string => match ($state) {
                        CreationSource::WEB => 'info',
                        CreationSource::SYSTEM => 'warning',
                        CreationSource::IMPORT => 'success',
                    })
                    ->label('Source'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
```

**Step 2: Commit**

```bash
git add app-modules/SystemAdmin/src/Filament/Resources/TeamResource/RelationManagers/NotesRelationManager.php
git commit -m "feat: add NotesRelationManager to sysadmin TeamResource"
```

---

### Task 8: Update TopTeamsTableWidget with Team View Link

**Files:**
- Modify: `app-modules/SystemAdmin/src/Filament/Widgets/TopTeamsTableWidget.php:55-59`

**Step 1: Add URL to team name column**

Add import at top of file:
```php
use Relaticle\SystemAdmin\Filament\Resources\TeamResource;
```

Update the `name` TextColumn (line 55-59) to add `->url()`:
```php
TextColumn::make('name')
    ->label('Team')
    ->searchable()
    ->sortable()
    ->weight('semibold')
    ->url(fn (Team $record): string => TeamResource::getUrl('view', ['record' => $record])),
```

**Step 2: Commit**

```bash
git add app-modules/SystemAdmin/src/Filament/Widgets/TopTeamsTableWidget.php
git commit -m "feat: link team names in dashboard widget to team view page"
```

---

### Task 9: Final Verification

**Step 1: Run static analysis**

Run: `./vendor/bin/phpstan analyse app-modules/SystemAdmin/src/Filament/Resources/TeamResource --level=5`
Expected: No errors.

**Step 2: Run existing tests**

Run: `php artisan test --filter=SystemAdmin`
Expected: All tests pass.

**Step 3: Verify routes**

Run: `php artisan route:list --path=sysadmin/teams`
Expected: Routes for index, create, view, edit.
