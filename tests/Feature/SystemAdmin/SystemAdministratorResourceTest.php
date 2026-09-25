<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\SystemAdmin\Actions\DeleteSystemAdministrator;
use Relaticle\SystemAdmin\Actions\UpdateSystemAdministrator;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages\CreateSystemAdministrator;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages\EditSystemAdministrator;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages\ListSystemAdministrators;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\Pages\ViewSystemAdministrator;
use Relaticle\SystemAdmin\Filament\Resources\SystemAdministrators\SystemAdministratorResource;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Relaticle\SystemAdmin\Rules\KeepsALastSuperAdministrator;

mutates(SystemAdministratorResource::class, KeepsALastSuperAdministrator::class, UpdateSystemAdministrator::class, DeleteSystemAdministrator::class);

beforeEach(function (): void {
    $this->actingAs(
        SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::SuperAdministrator]),
        'sysadmin',
    );
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

it('renders the create page', function (): void {
    livewire(CreateSystemAdministrator::class)->assertOk();
});

it('creates an administrator who can authenticate with the given password', function (): void {
    livewire(CreateSystemAdministrator::class)
        ->fillForm([
            'name' => 'New Admin',
            'email' => 'new-admin@example.test',
            'role' => SystemAdministratorRole::SuperAdministrator->value,
            'password' => 'creation-password',
            'password_confirmation' => 'creation-password',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = SystemAdministrator::query()->where('email', 'new-admin@example.test')->firstOrFail();

    expect($created->password)->not->toBe('creation-password')
        ->and(Auth::guard('sysadmin')->attempt([
            'email' => 'new-admin@example.test',
            'password' => 'creation-password',
        ]))->toBeTrue();
});

it('changes an administrator password', function (): void {
    $target = SystemAdministrator::factory()->create();

    livewire(EditSystemAdministrator::class, ['record' => $target->getKey()])
        ->fillForm([
            'password' => 'rotated-password',
            'password_confirmation' => 'rotated-password',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Auth::guard('sysadmin')->attempt([
        'email' => $target->email,
        'password' => 'rotated-password',
    ]))->toBeTrue();
});

it('keeps the existing password when the field is left blank', function (): void {
    $target = SystemAdministrator::factory()->create(['password' => 'original-password']);

    livewire(EditSystemAdministrator::class, ['record' => $target->getKey()])
        ->fillForm(['name' => 'Renamed Admin'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->name)->toBe('Renamed Admin')
        ->and(Auth::guard('sysadmin')->attempt([
            'email' => $target->email,
            'password' => 'original-password',
        ]))->toBeTrue();
});

it('refuses to give the last Super Administrator another role', function (): void {
    $lastSuperAdministrator = Auth::guard('sysadmin')->user();
    SystemAdministrator::factory()->administrator()->create();

    livewire(EditSystemAdministrator::class, ['record' => $lastSuperAdministrator->getKey()])
        ->fillForm(['role' => SystemAdministratorRole::Administrator->value])
        ->call('save')
        ->assertHasFormErrors(['role']);

    expect($lastSuperAdministrator->refresh()->role)->toBe(SystemAdministratorRole::SuperAdministrator);
});

it('gives a Super Administrator another role once a second one exists', function (): void {
    $target = SystemAdministrator::factory()->create();

    livewire(EditSystemAdministrator::class, ['record' => $target->getKey()])
        ->fillForm(['role' => SystemAdministratorRole::Administrator->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->refresh()->role)->toBe(SystemAdministratorRole::Administrator);
});

it('waits for a concurrent staff change before demoting the remaining super', function (string $change): void {
    $firstAdministrator = Auth::guard('sysadmin')->user();
    $secondAdministrator = SystemAdministrator::factory()->create();
    $connectionName = DB::getDefaultConnection();
    $connectionConfig = config("database.connections.{$connectionName}");
    DB::connection($connectionName)->commit();

    config()->set('database.connections.staff_lock_holder', $connectionConfig);
    config()->set('database.connections.staff_writer', $connectionConfig);
    $lockHolder = DB::connection('staff_lock_holder');
    $writer = DB::connection('staff_writer');

    try {
        $lockHolder->beginTransaction();
        $pendingAdministrator = $lockHolder->table('system_administrators')->where('id', $firstAdministrator->getKey());

        match ($change) {
            'demote' => $pendingAdministrator->update(['role' => SystemAdministratorRole::Administrator->value]),
            'delete' => $pendingAdministrator->delete(),
        };

        $writer->statement("SET lock_timeout TO '250ms'");
        DB::setDefaultConnection('staff_writer');
        $this->actingAs(SystemAdministrator::query()->findOrFail($secondAdministrator->getKey()), 'sysadmin');

        $editor = livewire(EditSystemAdministrator::class, ['record' => $secondAdministrator->getKey()])
            ->fillForm(['role' => SystemAdministratorRole::Administrator->value]);

        expect(fn (): Testable => $editor->call('save', false))
            ->toThrow(QueryException::class, 'lock timeout');

        $lockHolder->commit();
        $writer->statement('SET lock_timeout TO DEFAULT');

        livewire(EditSystemAdministrator::class, ['record' => $secondAdministrator->getKey()])
            ->fillForm(['role' => SystemAdministratorRole::Administrator->value])
            ->call('save', false)
            ->assertHasFormErrors(['role']);

        expect($secondAdministrator->fresh()->role)->toBe(SystemAdministratorRole::SuperAdministrator);
    } finally {
        if ($lockHolder->transactionLevel() > 0) {
            $lockHolder->rollBack();
        }

        DB::setDefaultConnection($connectionName);
        DB::connection($connectionName)->table('system_administrators')
            ->whereIn('id', [$firstAdministrator->getKey(), $secondAdministrator->getKey()])
            ->delete();
        DB::purge('staff_lock_holder');
        DB::purge('staff_writer');
    }
})->with(['demote', 'delete']);

it('never bulk deletes the acting Super Administrator', function (): void {
    $administrator = Auth::guard('sysadmin')->user();
    $otherAdministrator = SystemAdministrator::factory()->administrator()->create();

    livewire(ListSystemAdministrators::class)
        ->selectTableRecords([$administrator->getKey(), $otherAdministrator->getKey()])
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]]);

    $this->assertModelExists($administrator);
    $this->assertModelMissing($otherAdministrator);
});

it('does not write staff through an edit navigation action', function (string $page): void {
    $administrator = SystemAdministrator::factory()->create();
    $name = $administrator->name;
    $action = TestAction::make('edit');

    if ($page === ListSystemAdministrators::class) {
        $action->table($administrator);
    }

    livewire($page, ['record' => $administrator->getKey()])
        ->callAction($action, data: ['name' => 'Changed outside the edit page']);

    expect($administrator->refresh()->name)->toBe($name);
})->with([ListSystemAdministrators::class, ViewSystemAdministrator::class]);

it('renders each role with its own label and badge colour', function (): void {
    $superAdministrator = SystemAdministrator::factory()->create();
    $administrator = SystemAdministrator::factory()->administrator()->create();

    livewire(ListSystemAdministrators::class)
        ->assertTableColumnFormattedStateSet('role', 'Super Administrator', $superAdministrator)
        ->assertTableColumnFormattedStateSet('role', 'Administrator', $administrator)
        ->assertTableColumnStateSet('role', SystemAdministratorRole::Administrator, $administrator);

    expect(SystemAdministratorRole::SuperAdministrator->getColor())->toBe('danger')
        ->and(SystemAdministratorRole::Administrator->getColor())->toBe('warning');
});
