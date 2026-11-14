<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;

dataset('filter bar pages', [
    'companies' => [ListCompanies::class, Company::class],
    'people' => [ListPeople::class, People::class],
    'opportunities' => [ListOpportunities::class, Opportunity::class],
    'tasks' => [ManageTasks::class, Task::class],
    'notes' => [ManageNotes::class, Note::class],
]);
