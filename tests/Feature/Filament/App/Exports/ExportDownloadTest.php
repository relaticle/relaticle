<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Jobs\CompleteExport;
use App\Models\Company;
use App\Models\Export;
use App\Models\User;
use App\Policies\ExportPolicy;
use App\Support\CurrentWorkspace;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\Jobs\ExportCompletion;
use Filament\Facades\Filament;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Storage;

mutates(ExportPolicy::class, CompleteExport::class);

function completeQueuedExport(Export $export): void
{
    /** @var CompleteExport $job */
    $job = resolve(ExportCompletion::class, [
        'export' => $export,
        'columnMap' => ['name' => 'Name'],
        'formats' => [ExportFormat::Csv],
        'options' => [],
        'authGuard' => 'web',
    ])->onConnection('redis');

    new Pipeline(app())
        ->send($job)
        ->through($job->middleware())
        ->then(function (CompleteExport $job): void {
            $job->handle();
        });
}

beforeEach(function (): void {
    Storage::fake('local');

    $this->workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->getKey()]);
    $this->workspace->users()->attach($this->user, ['role' => 'admin']);
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);

    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);

    livewire(ListCompanies::class)->callAction('export')->assertHasNoFormErrors();

    $this->export = Export::query()->latest()->firstOrFail();
    $this->downloadUrl = route('filament.exports.download', ['export' => $this->export, 'format' => 'csv']);
});

it('lets the owner of a workspace export download it', function (): void {
    expect($this->export->workspace_id)->toBe($this->workspace->getKey());

    $this->actingAs($this->user)->get($this->downloadUrl)->assertOk();
});

it('stops the owner downloading an export once removed from its workspace', function (): void {
    $this->workspace->removeUser($this->user);

    $this->actingAs($this->user->fresh())
        ->get($this->downloadUrl)
        ->assertForbidden();
});

it('stops another member downloading an export they do not own', function (): void {
    $teammate = User::factory()->create();
    $this->workspace->users()->attach($teammate, ['role' => 'admin']);

    $this->actingAs($teammate)
        ->get($this->downloadUrl)
        ->assertForbidden();
});

it('keeps owner-only access for a legacy export without a workspace', function (): void {
    $this->export->forceFill(['workspace_id' => null])->save();
    $this->workspace->removeUser($this->user);

    $this->actingAs($this->user->fresh())
        ->get($this->downloadUrl)
        ->assertOk();
});

it('sends the completion notice to the workspace the export ran in', function (): void {
    $otherWorkspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $otherWorkspace->users()->attach($this->user, ['role' => 'member']);
    $this->user->switchWorkspace($otherWorkspace);
    resolve(CurrentWorkspace::class)->set($otherWorkspace);

    completeQueuedExport($this->export);

    expect($this->user->notifications()->sole()->workspace_id)->toBe($this->workspace->getKey())
        ->and(resolve(CurrentWorkspace::class)->get()?->getKey())->toBe($otherWorkspace->getKey());
});

it('completes an export stored without a workspace in the bound workspace', function (): void {
    $this->export->forceFill(['workspace_id' => null])->save();
    resolve(CurrentWorkspace::class)->set($this->workspace);

    completeQueuedExport($this->export->fresh());

    expect($this->user->notifications()->sole()->workspace_id)->toBe($this->workspace->getKey());
});
