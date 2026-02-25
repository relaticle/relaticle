<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

describe('SystemAdmin Security', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('sysadmin');
    });

    it('enforces complete authentication isolation', function () {
        $admin = SystemAdministrator::factory()->create();
        $user = User::factory()->create();

        expect($admin->canAccessPanel(Filament::getPanel('app')))->toBeFalse()
            ->and($user->canAccessPanel(Filament::getPanel('sysadmin')))->toBeFalse();

        $this->actingAs($admin, 'sysadmin');
        $this->assertAuthenticatedAs($admin, 'sysadmin');
        $this->assertGuest('web');
    });

    it('enforces role-based authorization', function () {
        $superAdmin = SystemAdministrator::factory()->create([
            'role' => SystemAdministratorRole::SuperAdministrator,
        ]);

        $otherAdmin = SystemAdministrator::factory()->create([
            'role' => SystemAdministratorRole::SuperAdministrator,
        ]);

        $this->actingAs($superAdmin, 'sysadmin');

        expect(auth('sysadmin')->user()->can('create', SystemAdministrator::class))->toBeTrue()
            ->and(auth('sysadmin')->user()->can('viewAny', SystemAdministrator::class))->toBeTrue()
            ->and(auth('sysadmin')->user()->can('update', $otherAdmin))->toBeTrue()
            ->and(auth('sysadmin')->user()->can('delete', $otherAdmin))->toBeTrue()
            ->and(auth('sysadmin')->user()->can('delete', $superAdmin))->toBeFalse();
    });

    it('redirects unauthenticated visitors to sysadmin login', function (string $route) {
        $this->get($route)->assertRedirect('/sysadmin/login');
    })->with([
        'dashboard' => '/sysadmin',
        'companies' => '/sysadmin/companies',
        'imports' => '/sysadmin/imports',
        'users' => '/sysadmin/users',
        'teams' => '/sysadmin/teams',
        'system-administrators' => '/sysadmin/system-administrators',
    ]);

    it('blocks regular app users from accessing sysadmin panel', function (string $route) {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->get($route)
            ->assertRedirect('/sysadmin/login');
    })->with([
        'dashboard' => '/sysadmin',
        'companies' => '/sysadmin/companies',
        'users' => '/sysadmin/users',
    ]);

    it('blocks unverified sysadmin from accessing panel routes', function () {
        $unverifiedAdmin = SystemAdministrator::factory()->unverified()->create();

        $this->actingAs($unverifiedAdmin, 'sysadmin')
            ->get('/sysadmin')
            ->assertForbidden();
    });

    it('allows verified sysadmin to access panel routes', function () {
        $admin = SystemAdministrator::factory()->create();

        $this->actingAs($admin, 'sysadmin')
            ->get('/sysadmin/system-administrators')
            ->assertOk();
    });

});
