<?php

declare(strict_types=1);

namespace App\Support\Impersonation;

use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

final readonly class Impersonator
{
    private const string ADMINISTRATOR_ID = 'impersonation.administrator_id';

    private const string TARGET_ID = 'impersonation.target_id';

    private const string PREVIOUS_USER_ID = 'impersonation.previous_user_id';

    // AuthenticateSession only writes the hash when absent, so a stale one logs the
    // assumed user straight back out.
    private const string PASSWORD_HASH = 'password_hash_web';

    public function start(Request $request, string $administratorId, User $target): void
    {
        $session = $request->session();
        $previousUserId = Auth::guard('web')->id();

        $this->assume($session, $target);

        $session->put(self::ADMINISTRATOR_ID, $administratorId);
        $session->put(self::TARGET_ID, $target->getKey());
        $session->put(self::PREVIOUS_USER_ID, $previousUserId);
    }

    public function stop(Request $request): void
    {
        if (! $this->active($request)) {
            return;
        }

        $this->record(
            'impersonation_stopped',
            $this->administrator($this->administratorId($request)),
            Auth::guard('web')->user(),
        );

        $session = $request->session();
        $previousUser = User::query()->find($session->get(self::PREVIOUS_USER_ID));

        $session->forget([self::ADMINISTRATOR_ID, self::TARGET_ID, self::PREVIOUS_USER_ID]);

        if ($previousUser instanceof User) {
            $this->assume($session, $previousUser);

            return;
        }

        AuthenticationSession::clear();
        $session->forget([Auth::guard('web')->getName(), self::PASSWORD_HASH]);
        $session->migrate(true);
        Auth::guard('web')->forgetUser();
    }

    public function active(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $targetId = $request->session()->get(self::TARGET_ID);

        return $targetId !== null && Auth::guard('web')->id() === $targetId;
    }

    public function administratorId(Request $request): ?string
    {
        if (! $this->active($request)) {
            return null;
        }

        $administratorId = $request->session()->get(self::ADMINISTRATOR_ID);

        return is_string($administratorId) ? $administratorId : null;
    }

    /**
     * Resolved through the guard's provider: the app host never holds a sysadmin
     * session, and app code may not name the SystemAdmin model.
     */
    public function administrator(mixed $id): (Model&Authenticatable)|null
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        $administrator = Auth::createUserProvider(config('auth.guards.sysadmin.provider'))?->retrieveById($id);

        return $administrator instanceof Model ? $administrator : null;
    }

    public function claim(Request $request): bool
    {
        $nonce = $request->query('nonce');

        if (! is_string($nonce) || $nonce === '') {
            return false;
        }

        return Cache::add(
            'impersonation.consumed:'.$nonce,
            true,
            max(1, (int) $request->query('expires') - now()->getTimestamp()),
        );
    }

    /**
     * Swapping from one customer to another stops the first, so the record has to
     * be written where the state is known rather than in the stop route.
     */
    public function record(string $event, (Model&Authenticatable)|null $administrator, ?Authenticatable $target): void
    {
        if (! $target instanceof User) {
            return;
        }

        activity((string) config('activitylog.default_log_name'))
            ->causedBy($administrator)
            ->performedOn($target)
            ->withProperties(['email' => $target->email])
            ->event($event)
            ->log($event);
    }

    // Never login()/logout(): Login stamps last_login_at (engagement, Mailcoach tags) and
    // Logout cycles the remember token, signing the customer out of every device.
    private function assume(Session $session, User $user): void
    {
        $guard = Auth::guard('web');

        AuthenticationSession::clear();

        $session->put($guard->getName(), $user->getAuthIdentifier());
        $session->migrate(true);
        $session->put(self::PASSWORD_HASH, $user->getAuthPassword());

        $guard->setUser($user);

        AuthenticationSession::markComplete($user);
    }
}
