<?php

declare(strict_types=1);

namespace App\Support\Impersonation;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final readonly class Impersonator
{
    private const string ADMINISTRATOR_ID = 'impersonation.administrator_id';

    private const string TARGET_ID = 'impersonation.target_id';

    private const string PREVIOUS_USER_ID = 'impersonation.previous_user_id';

    /**
     * Laravel's and Filament's AuthenticateSession both key off the default guard
     * name and only write the hash when it is absent, so swapping the user without
     * rewriting it logs the target straight back out.
     */
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

        $this->record('impersonation_stopped', Auth::guard('web')->user());

        $session = $request->session();
        $previousUser = User::query()->find($session->get(self::PREVIOUS_USER_ID));

        $session->forget([self::ADMINISTRATOR_ID, self::TARGET_ID, self::PREVIOUS_USER_ID]);

        if ($previousUser instanceof User) {
            $this->assume($session, $previousUser);

            return;
        }

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
     * Swapping from one customer to another stops the first, so the record has to
     * be written where the state is known rather than in the stop route.
     */
    public function record(string $event, ?Authenticatable $target): void
    {
        if (! $target instanceof User) {
            return;
        }

        activity((string) config('activitylog.default_log_name'))
            ->causedBy(Auth::guard('sysadmin')->user())
            ->performedOn($target)
            ->withProperties(['email' => $target->email])
            ->event($event)
            ->log($event);
    }

    /**
     * Swaps the web guard's user without firing Login or Logout. Login writes
     * `last_login_at`, which feeds the engagement buckets and the Mailcoach
     * subscriber tags; logout cycles the remember token, signing the customer
     * out of every device they stayed signed in on.
     */
    private function assume(Session $session, User $user): void
    {
        $guard = Auth::guard('web');

        $session->put($guard->getName(), $user->getAuthIdentifier());
        $session->migrate(true);
        $session->put(self::PASSWORD_HASH, $user->getAuthPassword());

        $guard->setUser($user);
    }
}
