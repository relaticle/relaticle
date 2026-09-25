<?php

declare(strict_types=1);

use App\Http\Middleware\StopImpersonationOnLogout;
use App\Livewire\App\Profile\ScheduledDeletionInterstitial;
use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\WorkspaceScope;
use App\Models\Company;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use App\Support\Impersonation\Impersonator;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

use function Pest\Laravel\actingAs;

mutates(Impersonator::class, StopImpersonationOnLogout::class, ScheduledDeletionInterstitial::class);

function impersonationLink(User $target, array $parameters = [], ?SystemAdministrator $administrator = null): string
{
    return URL::temporarySignedRoute(
        'impersonation.start',
        now()->addSeconds(60),
        [
            'user' => $target->getKey(),
            'administrator' => ($administrator ?? test()->administrator)->getKey(),
            'nonce' => Str::random(40),
            ...$parameters,
        ],
        absolute: false,
    );
}

beforeEach(function (): void {
    $this->administrator = SystemAdministrator::factory()->create();
    $this->customer = User::factory()->withWorkspace()->create();
});

it('signs the administrator in as the customer', function (): void {
    $this->get(impersonationLink($this->customer))
        ->assertRedirect(url()->getAppUrl());

    expect(Auth::guard('web')->id())->toBe($this->customer->getKey());
});

it('keeps the customer signed in on the next panel request', function (): void {
    $administratorsOwnAccount = User::factory()->withWorkspace()->create();

    actingAs($administratorsOwnAccount, 'web');
    $this->get(impersonationLink($this->customer));

    $this->get(url()->getAppUrl($this->customer->currentWorkspace->slug))
        ->assertSuccessful();

    expect(Auth::guard('web')->id())->toBe($this->customer->getKey());
});

it('keeps the customer signed in when the session held the administrators own password hash', function (): void {
    $administratorsOwnAccount = User::factory()->withWorkspace()->create(['password' => Hash::make('a-different-password')]);

    actingAs($administratorsOwnAccount, 'web');
    session()->put('password_hash_web', $administratorsOwnAccount->getAuthPassword());

    $this->get(impersonationLink($this->customer));

    $this->get(url()->getAppUrl($this->customer->currentWorkspace->slug))
        ->assertSuccessful();

    expect(Auth::guard('web')->id())->toBe($this->customer->getKey());
});

it('shows the impersonation banner only while impersonating', function (): void {
    $workspacePath = url()->getAppUrl($this->customer->currentWorkspace->slug);

    actingAs($this->customer, 'web')
        ->get($workspacePath)
        ->assertSuccessful()
        ->assertDontSee(route('impersonation.stop'));

    $this->get(impersonationLink($this->customer));

    $this->get($workspacePath)
        ->assertSuccessful()
        ->assertSee(__('filament/panel.impersonation.banner', ['name' => $this->customer->name, 'email' => $this->customer->email]))
        ->assertSee(route('impersonation.stop'));
});

it('lands in the named workspace', function (): void {
    $workspace = $this->customer->currentWorkspace;

    $this->get(impersonationLink($this->customer, ['workspace' => $workspace->getKey()]))
        ->assertRedirect(url()->getAppUrl($workspace->slug));
});

it('ignores a workspace the target does not belong to', function (): void {
    $stranger = User::factory()->withWorkspace()->create();

    $this->get(impersonationLink($this->customer, ['workspace' => $stranger->currentWorkspace->getKey()]))
        ->assertRedirect(url()->getAppUrl());
});

it('restores the account the administrator was signed into', function (): void {
    $administratorsOwnAccount = User::factory()->withWorkspace()->create();

    actingAs($administratorsOwnAccount, 'web');
    $this->get(impersonationLink($this->customer));

    $this->delete(route('impersonation.stop'))
        ->assertRedirect(route('filament.sysadmin.resources.users.index'));

    $this->delete(route('impersonation.stop'));

    expect(Auth::guard('web')->id())->toBe($administratorsOwnAccount->getKey());
});

it('records the stop when the administrator switches to another customer', function (): void {
    $otherCustomer = User::factory()->withWorkspace()->create();

    $this->get(impersonationLink($this->customer));
    $this->get(impersonationLink($otherCustomer));

    $stopped = Activity::withoutGlobalScope(WorkspaceScope::class)
        ->where('event', 'impersonation_stopped')
        ->get();

    expect($stopped)->toHaveCount(1)
        ->and($stopped->first()->subject_id)->toBe($this->customer->getKey())
        ->and(Auth::guard('web')->id())->toBe($otherCustomer->getKey());
});

it('signs out of the app when the administrator had no account signed in', function (): void {
    $this->get(impersonationLink($this->customer));

    $this->delete(route('impersonation.stop'));

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('leaves the customer login timestamp and remember token untouched', function (): void {
    $this->customer->forceFill([
        'last_login_at' => now()->subMonths(3),
        'remember_token' => 'keep-me-signed-in',
    ])->saveQuietly();

    $this->get(impersonationLink($this->customer));
    $this->delete(route('impersonation.stop'));

    $this->customer->refresh();

    expect($this->customer->last_login_at->isSameDay(now()->subMonths(3)))->toBeTrue()
        ->and($this->customer->remember_token)->toBe('keep-me-signed-in');
});

it('refuses an administrator who may not impersonate', function (): void {
    $administrator = SystemAdministrator::factory()->administrator()->create();

    $this->get(impersonationLink($this->customer, [], $administrator))
        ->assertForbidden();

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('refuses an unsigned link', function (): void {
    $this->get('/impersonate/'.$this->customer->getKey())
        ->assertForbidden();
});

it('refuses a link that was already used', function (): void {
    $link = impersonationLink($this->customer);

    $this->get($link)->assertRedirect();
    $this->delete(route('impersonation.stop'));

    $this->get($link)->assertForbidden();

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('refuses an expired link', function (): void {
    $link = impersonationLink($this->customer);

    $this->travel(61)->seconds();

    $this->get($link)->assertForbidden();

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('refuses a link naming an administrator that does not exist', function (): void {
    $administrator = SystemAdministrator::factory()->create();
    $link = impersonationLink($this->customer, [], $administrator);
    $administrator->delete();

    $this->get($link)->assertForbidden();

    expect(Auth::guard('web')->check())->toBeFalse();
});

it('records the start and the stop against the administrator', function (): void {
    $this->get(impersonationLink($this->customer));
    $this->delete(route('impersonation.stop'));

    $events = Activity::withoutGlobalScope(WorkspaceScope::class)
        ->whereIn('event', ['impersonation_started', 'impersonation_stopped'])
        ->get();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('causer_id')->unique()->all())->toBe([$this->administrator->getKey()])
        ->and($events->pluck('subject_id')->unique()->all())->toBe([$this->customer->getKey()]);
});

it('tags a write made during impersonation with the administrator', function (): void {
    $this->get(impersonationLink($this->customer));

    $company = Company::factory()->create([
        'workspace_id' => $this->customer->currentWorkspace->getKey(),
        'creator_id' => $this->customer->getKey(),
    ]);

    $activity = Activity::withoutGlobalScope(WorkspaceScope::class)
        ->where('subject_id', $company->getKey())
        ->latest('id')
        ->first();

    expect($activity->properties->get('impersonated_by'))->toBe($this->administrator->getKey());
});

it('tags a write made by a job queued during impersonation with the administrator', function (): void {
    $this->get(impersonationLink($this->customer));

    $workspaceId = $this->customer->currentWorkspace->getKey();
    $customerId = $this->customer->getKey();

    dispatch(fn (): Company => Company::factory()->create([
        'name' => 'Queued during impersonation',
        'workspace_id' => $workspaceId,
        'creator_id' => $customerId,
    ]))->onConnection('database');

    session()->flush();
    Auth::guard('web')->forgetUser();
    Context::flush();

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

    $company = Company::query()->where('name', 'Queued during impersonation')->sole();

    $activity = Activity::withoutGlobalScope(WorkspaceScope::class)
        ->where('subject_id', $company->getKey())
        ->latest('id')
        ->first();

    expect($activity->properties->get('impersonated_by'))->toBe($this->administrator->getKey());
});

it('leaves an ordinary write untagged', function (): void {
    actingAs($this->customer, 'web');

    $company = Company::factory()->create([
        'workspace_id' => $this->customer->currentWorkspace->getKey(),
        'creator_id' => $this->customer->getKey(),
    ]);

    $activity = Activity::withoutGlobalScope(WorkspaceScope::class)
        ->where('subject_id', $company->getKey())
        ->latest('id')
        ->first();

    expect($activity->properties->get('impersonated_by'))->toBeNull();
});

it('drops the administrators identity confirmation when assuming the customer', function (): void {
    $administratorsOwnAccount = User::factory()->withWorkspace()->create();

    actingAs($administratorsOwnAccount, 'web');
    session()->put('auth.password_confirmed_at', time());
    AuthenticationSession::startOperation($administratorsOwnAccount, 'set_password', null);

    $this->get(impersonationLink($this->customer));

    $this->get(route('auth.socialite.link.redirect', ['provider' => 'google']))
        ->assertRedirect(route('password.confirm'));

    expect(AuthenticationSession::pendingOperation())->toBe([]);
});

it('drops the confirmation the customer session held when restoring the administrator', function (): void {
    $administratorsOwnAccount = User::factory()->withWorkspace()->create();

    actingAs($administratorsOwnAccount, 'web');
    $this->get(impersonationLink($this->customer));
    session()->put('auth.password_confirmed_at', time());

    $this->delete(route('impersonation.stop'));

    $this->get(route('auth.socialite.link.redirect', ['provider' => 'google']))
        ->assertRedirect(route('password.confirm'));
});

it('keeps an mfa enrolled customer signed in on the next panel request', function (): void {
    $customer = User::factory()->withConfirmedMfa()->withWorkspace()->create();

    $this->get(impersonationLink($customer));

    $this->get(url()->getAppUrl($customer->currentWorkspace->slug))
        ->assertSuccessful();

    expect(Auth::guard('web')->id())->toBe($customer->getKey());
});

it('keeps the administrators own mfa session complete after stopping', function (): void {
    $administratorsOwnAccount = User::factory()->withConfirmedMfa()->withWorkspace()->create();

    actingAs($administratorsOwnAccount, 'web');
    AuthenticationSession::markComplete($administratorsOwnAccount);
    $this->get(impersonationLink($this->customer));

    $this->delete(route('impersonation.stop'));

    $this->get(url()->getAppUrl($administratorsOwnAccount->currentWorkspace->slug))
        ->assertSuccessful();

    expect(Auth::guard('web')->id())->toBe($administratorsOwnAccount->getKey());
});

it('ends the impersonation instead of signing the customer out', function (string $routeName, array $parameters): void {
    $this->customer->forceFill(['remember_token' => 'keep-me-signed-in'])->saveQuietly();

    $this->get(impersonationLink($this->customer));

    $this->post(route($routeName, $parameters))
        ->assertRedirect(route('filament.sysadmin.resources.users.index'));

    expect($this->customer->refresh()->remember_token)->toBe('keep-me-signed-in')
        ->and(Auth::guard('web')->check())->toBeFalse()
        ->and(session('impersonation.target_id'))->toBeNull();
})->with([
    'panel sign out' => ['filament.app.auth.logout', []],
    'fortify sign out' => ['logout', []],
    'invitation account switch' => ['workspace-invitations.token.switch', ['token' => str_repeat('a', 40)]],
]);

it('ends the impersonation from the scheduled deletion interstitial instead of signing the customer out', function (): void {
    $customer = User::factory()->withPersonalWorkspace()->scheduledForDeletion()->create();
    $customer->forceFill(['remember_token' => 'keep-me-signed-in'])->saveQuietly();

    $this->get(impersonationLink($customer));
    app()->rebinding('request', fn (Application $app, Request $request) => $request->setLaravelSession($app['session.store']));

    livewire(ScheduledDeletionInterstitial::class)
        ->callAction('logout')
        ->assertRedirect(route('filament.sysadmin.resources.users.index'));

    expect($customer->refresh()->remember_token)->toBe('keep-me-signed-in')
        ->and(Auth::guard('web')->check())->toBeFalse();
});

it('leaves the sysadmin panels own sign out alone', function (): void {
    $this->get(impersonationLink($this->customer));

    actingAs($this->administrator, 'sysadmin')
        ->post(route('filament.sysadmin.auth.logout'))
        ->assertRedirect(route('filament.sysadmin.auth.login'));

    expect(Auth::guard('sysadmin')->check())->toBeFalse();
});
