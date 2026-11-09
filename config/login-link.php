<?php

declare(strict_types=1);

use Spatie\LoginLink\Http\Controllers\LoginLinkController;

// An Amp orb serves the app on its portal hostname, which only the thread's participants can open.
$orbPortalDomain = env('AMP_ORB') ? env('AMP_PORTAL_DOMAIN') : null;

$isDevelopmentHost = static fn (mixed $host): bool => is_string($host)
    && (in_array($host, ['localhost', '127.0.0.1'], true)
        || str_ends_with($host, '.test')
        || (is_string($orbPortalDomain) && $orbPortalDomain !== '' && str_ends_with($host, ".{$orbPortalDomain}")));

return [
    /*
     * .env.example ships APP_ENV=local and TrustHosts is off there, so the request host is
     * attacker-chosen: only an APP_URL on a development host enables login links.
     */
    'allowed_environments' => $isDevelopmentHost(parse_url((string) config('app.url'), PHP_URL_HOST)) ? ['local'] : [],

    /*
     * Login links will only work in these hosts.
     */
    'allowed_hosts' => array_values(array_unique(array_filter([
        'localhost',
        '127.0.0.1',
        'relaticle.test',
        'app.relaticle.test',
        parse_url((string) config('app.url'), PHP_URL_HOST),
        config('app.app_panel_domain'),
        config('app.sysadmin_domain'),
    ], $isDevelopmentHost))),

    /*
     * The package will automatically create a user model when trying
     * to log in a user that doesn't exist. Off: the request names the
     * guard and user model, so creating users would let it mint accounts.
     */
    'automatically_create_missing_users' => false,

    /*
     * The user model that should be logged in. If this is set to `null`
     * we'll take a look at the model used for the `users`
     * provider in config/auth.php
     */
    'user_model' => null,

    /*
     * After a login link is clicked, we'll redirect the user to this route.
     * If it is set to `null`, we'll redirect the user to their last intended/requested url.
     * You can set it to `/`, for making redirect to the root page.
     */
    'redirect_route_name' => null,

    /*
     * The package will register a route that points to this controller. To have fine
     * grained control over what happens when a login link is clicked, you can
     * override this class.
     */
    'login_link_controller' => LoginLinkController::class,

    /*
     * This middleware will be applied on the route
     * that logs in a user via a link.
     */
    'middleware' => ['web'],
];
