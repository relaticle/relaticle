<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Inline script to detect system dark mode preference and apply it immediately --}}
    <script>
        (function() {
            const appearance = @js($appearance ?? 'system');

            if (appearance === 'system') {
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                if (prefersDark) {
                    document.documentElement.classList.add('dark');
                }
            }
        })();
    </script>

    <style>
        html {
            background-color: oklch(0.985 0.002 247.839); /* gray-50 */
            color-scheme: light;
        }

        html.dark {
            background-color: oklch(0.13 0.028 261.692); /* gray-950 */
            color-scheme: dark;
        }
    </style>

    <title>{{ __('mcp.consent.title', ['client' => $client->name]) }} - {{ config('app.name', 'MCP Server') }}</title>

    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="Authorize MCP" />
    <link rel="manifest" href="/site.webmanifest" />

    @vite(['resources/css/app.css'])
</head>
<body class="font-sans antialiased bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
<div class="min-h-screen flex items-center justify-center p-4 sm:p-6">
    <div class="w-full max-w-md">
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            @php
                $requestedScopes = collect($scopes)->pluck('id');
                $asksForRestScopes = $requestedScopes->intersect(['read', 'create', 'update', 'delete'])->isNotEmpty();
                $connectsOverMcp = $requestedScopes->contains(\Laravel\Mcp\Server\Registrar::OAUTH_SCOPE);
                $namesRecordLines = $asksForRestScopes && ! $connectsOverMcp;

                $permissions = collect([
                    ['abilities' => ['read'], 'label' => __('mcp.consent.permissions.read'), 'asked' => ! $namesRecordLines || $requestedScopes->contains('read')],
                    ['abilities' => ['create', 'update'], 'label' => __('mcp.consent.permissions.write'), 'asked' => ! $namesRecordLines || $requestedScopes->intersect(['create', 'update'])->isNotEmpty()],
                    ['abilities' => ['delete'], 'label' => __('mcp.consent.permissions.delete'), 'asked' => ! $namesRecordLines || $requestedScopes->contains('delete')],
                    ...collect(\App\Enums\EmailGrant::offered())->map(fn (\App\Enums\EmailGrant $grant): array => [
                        'abilities' => [$grant->value],
                        'label' => $grant->consentTitle(),
                        'asked' => $connectsOverMcp,
                    ]),
                ])->where('asked', true);

                $choosesWorkspace = $workspaces->count() > 1 || $pausedWorkspaceIds !== [];

                if (! $choosesWorkspace) {
                    $selectedWorkspaceId = $workspaces->first()?->getKey();
                }

                $selectedAbilities = $abilitiesByWorkspace[$selectedWorkspaceId] ?? [];

                $grantsNothingAsked = $permissions->every(fn (array $permission): bool => array_intersect($permission['abilities'], $selectedAbilities) === []);
            @endphp

            <!-- Header -->
            <div class="flex flex-col items-center gap-4 px-6 pt-8 pb-6 text-center">
                <x-brand.logo-lockup size="md" class="text-gray-900 dark:text-white" />

                <div class="space-y-1.5">
                    <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">
                        {{ __('mcp.consent.title', ['client' => $client->name]) }}
                    </h1>

                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        @if($choosesWorkspace || $workspaces->isEmpty())
                            {{ __('mcp.consent.intro.choose', ['client' => $client->name]) }}
                        @else
                            {{ __('mcp.consent.intro.one', ['client' => $client->name, 'workspace' => $workspaces->first()->name]) }}
                        @endif
                    </p>
                </div>
            </div>

            <div class="space-y-5 border-t border-gray-200 px-6 py-5 dark:border-gray-800">
                @if($workspaces->count() > 0)
                    @if($choosesWorkspace)
                        <div>
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('mcp.consent.workspace.heading') }}</h2>

                            <div class="mt-2 space-y-1.5" role="radiogroup" aria-label="{{ __('mcp.consent.workspace.aria_label') }}">
                                @foreach($workspaces as $workspace)
                                    @php($isPaused = in_array($workspace->getKey(), $pausedWorkspaceIds, true))
                                    <label @class([
                                        'flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border px-3 py-2 transition-colors',
                                        'cursor-pointer border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50 has-[:checked]:border-primary has-[:checked]:bg-primary-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-700 dark:hover:bg-gray-800/50 dark:has-[:checked]:border-primary-400 dark:has-[:checked]:bg-primary-950/50' => ! $isPaused,
                                        'cursor-not-allowed border-gray-200 bg-gray-50 opacity-60 dark:border-gray-800 dark:bg-gray-800/30' => $isPaused,
                                    ])>
                                        <input
                                            type="radio"
                                            name="workspace_id"
                                            value="{{ $workspace->getKey() }}"
                                            form="authorizeForm"
                                            data-abilities="{{ implode(' ', $abilitiesByWorkspace[$workspace->getKey()] ?? []) }}"
                                            required
                                            @disabled($isPaused)
                                            @checked($workspace->getKey() === $selectedWorkspaceId)
                                            class="size-4 shrink-0 accent-primary"
                                        >
                                        <span class="min-w-0 flex-1 text-sm font-medium break-words text-gray-900 dark:text-white">{{ $workspace->name }}</span>
                                        @if($isPaused)
                                            <span class="w-full pl-7 text-xs font-medium text-red-600 sm:w-auto sm:pl-0 sm:text-right dark:text-red-400">{{ __('mcp.consent.workspace.paused') }}</span>
                                        @elseif($workspace->personal_workspace)
                                            <span class="w-full pl-7 text-xs text-gray-500 sm:w-auto sm:pl-0 sm:text-right dark:text-gray-400">{{ __('mcp.consent.workspace.personal') }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>

                            <p class="mt-2 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                                {{ __('mcp.consent.workspace.description') }}
                            </p>

                            @if($workspaces->count() === count($pausedWorkspaceIds))
                                <p class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs leading-relaxed text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
                                    {{ __('mcp.consent.workspace.all_paused') }}
                                </p>
                            @endif
                        </div>
                    @else
                        <input type="hidden" name="workspace_id" value="{{ $selectedWorkspaceId }}" form="authorizeForm">
                    @endif

                    @if($selectedWorkspaceId)
                        <div>
                            <h2 id="permissions-heading" class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('mcp.consent.permissions.heading', ['client' => $client->name]) }}</h2>

                            <p
                                id="consentNone"
                                @if(! $grantsNothingAsked) hidden @endif
                                role="status"
                                class="mt-2 text-sm text-gray-700 dark:text-gray-300"
                            >{{ __('mcp.consent.permissions.none', ['client' => $client->name]) }}</p>

                            <ul
                                id="permissionList"
                                @if($grantsNothingAsked) hidden @endif
                                class="mt-2 space-y-1.5"
                                aria-labelledby="permissions-heading"
                                aria-live="polite"
                            >
                                @foreach($permissions as $permission)
                                    <li
                                        data-abilities-any="{{ implode(' ', $permission['abilities']) }}"
                                        @if(array_intersect($permission['abilities'], $selectedAbilities) === []) hidden @endif
                                        class="flex items-start gap-2.5 text-sm text-gray-700 dark:text-gray-300"
                                    >
                                        <svg class="mt-0.5 size-4 shrink-0 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4.5 4.5L19 7.5"/>
                                        </svg>
                                        <span class="min-w-0">{{ $permission['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>

                            <p class="mt-3 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                                {{ __('mcp.consent.permissions.excluded') }}
                            </p>
                        </div>
                    @endif
                @else
                    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 dark:border-red-900/50 dark:bg-red-950/40">
                        <p class="text-sm font-medium text-red-800 dark:text-red-200">{{ __('mcp.consent.workspace.none.heading') }}</p>
                        <p class="mt-1 text-xs leading-relaxed text-red-700 dark:text-red-300">
                            {{ __('mcp.consent.workspace.none.description') }}
                        </p>
                    </div>
                @endif
            </div>

            <!-- Footer With Buttons -->
            <div class="flex items-center gap-3 border-t border-gray-200 px-6 py-4 dark:border-gray-800">
                <!-- Deny Form -->
                <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="state" value="{{ $request->state }}">
                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="inline-flex h-10 w-full items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800">
                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        {{ __('mcp.consent.actions.cancel') }}
                    </button>
                </form>

                <!-- Approve Form -->
                <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="flex-1" id="authorizeForm">
                    @csrf
                    <input type="hidden" name="state" value="{{ $request->state }}">
                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" @disabled($workspaces->count() === 0 || $workspaces->count() === count($pausedWorkspaceIds) || $grantsNothingAsked) class="inline-flex h-10 w-full items-center justify-center gap-2 whitespace-nowrap rounded-lg bg-primary px-4 text-sm font-medium text-white transition-colors hover:bg-primary-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:pointer-events-none disabled:opacity-50" id="authorizeButton">
                        <svg id="loadingSpinner" class="hidden size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>

                        <span id="authorizeText">{{ __('mcp.consent.actions.authorize') }}</span>
                    </button>
                </form>
            </div>
        </div>

        <p class="mt-4 text-center text-xs leading-relaxed text-gray-500 dark:text-gray-400">
            <span class="break-all">{{ __('mcp.consent.signed_in_as', ['email' => $user->email]) }}</span>
            {{ __('mcp.consent.revoke_hint') }}
            @if($redirectHost)
                {{ __('mcp.consent.redirect', ['host' => $redirectHost]) }}
            @endif
        </p>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('authorizeForm');
        const button = document.getElementById('authorizeButton');
        const authorizeText = document.getElementById('authorizeText');
        const loadingSpinner = document.getElementById('loadingSpinner');

        let submitting = false;

        form.addEventListener('submit', function(e) {
            // Show loading state...
            submitting = true;
            button.disabled = true;
            authorizeText.textContent = @js(__('mcp.consent.actions.authorizing'));
            loadingSpinner.classList.remove('hidden');

            // After form submission, watch for redirect and close window...
            setTimeout(function() {
                const checkRedirect = setInterval(function() {
                    // If URL changed or we have OAuth params, redirect happened...
                    if (!window.location.href.includes('/oauth/authorize') ||
                        window.location.search.includes('code=') ||
                        window.location.search.includes('error=')) {
                        clearInterval(checkRedirect);
                        window.close();
                    }
                }, 100);

                // Fallback: Close after five seconds...
                setTimeout(function() {
                    clearInterval(checkRedirect);
                    window.close();
                }, 5000);
            }, 200);
        });

        const permissions = document.querySelectorAll('[data-abilities-any]');
        const permissionList = document.getElementById('permissionList');
        const nothingAllowed = document.getElementById('consentNone');

        const workspaceRadios = document.querySelectorAll('input[type="radio"][name="workspace_id"]');

        function listPermissionsOfCheckedWorkspace() {
            const checked = Array.from(workspaceRadios).find(function(radio) {
                return radio.checked;
            });

            if (! checked) {
                return;
            }

            const held = checked.dataset.abilities.split(' ');
            let listed = 0;

            permissions.forEach(function(permission) {
                permission.hidden = ! permission.dataset.abilitiesAny.split(' ').some(function(ability) {
                    return held.includes(ability);
                });

                if (! permission.hidden) {
                    listed++;
                }
            });

            permissionList.hidden = listed === 0;
            nothingAllowed.hidden = listed !== 0;
            button.disabled = submitting || listed === 0;
        }

        workspaceRadios.forEach(function(radio) {
            radio.addEventListener('change', listPermissionsOfCheckedWorkspace);
        });

        // A browser can restore another checked radio on reload or Back without firing change.
        window.addEventListener('pageshow', listPermissionsOfCheckedWorkspace);
        listPermissionsOfCheckedWorkspace();

        // Handle cancel button...
        const cancelForm = document.querySelector('form[method="POST"]:has(input[name="_method"][value="DELETE"])');
        if (cancelForm) {
            cancelForm.addEventListener('submit', function(e) {
                setTimeout(function() {
                    window.close();
                }, 200);
            });
        }
    });
</script>
</body>
</html>
