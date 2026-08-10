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
            <!-- Header -->
            <div class="flex flex-col items-center gap-4 px-6 pt-8 pb-6 text-center">
                <x-brand.logo-lockup size="md" class="text-gray-900 dark:text-white" />

                <div class="space-y-1.5">
                    <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">
                        {{ __('mcp.consent.title', ['client' => $client->name]) }}
                    </h1>

                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('mcp.consent.intro', ['client' => $client->name]) }}
                    </p>
                </div>
            </div>

            <div class="space-y-6 border-t border-gray-200 px-6 py-6 dark:border-gray-800">
                <!-- User Info -->
                <div class="flex flex-col gap-0.5 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-3 dark:border-gray-800 dark:bg-gray-800/40">
                    <span class="shrink-0 text-sm text-gray-500 dark:text-gray-400">{{ __('mcp.consent.signed_in_as') }}</span>
                    <span class="text-sm font-medium break-all text-gray-900 dark:text-white">{{ $user->email }}</span>
                </div>

                <!-- Workspace Picker -->
                @if($teams->count() > 0)
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('mcp.consent.workspace.heading') }}</h2>
                        <p class="mt-1 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                            {{ __('mcp.consent.workspace.description', ['client' => $client->name]) }}
                        </p>

                        <div class="mt-3 space-y-2" role="radiogroup" aria-label="{{ __('mcp.consent.workspace.aria_label') }}">
                            @foreach($teams as $team)
                                @php($isPaused = in_array($team->getKey(), $pausedTeamIds, true))
                                <label @class([
                                    'flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border px-4 py-3 transition-colors',
                                    'cursor-pointer border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50 has-[:checked]:border-primary has-[:checked]:bg-primary-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:border-gray-700 dark:hover:bg-gray-800/50 dark:has-[:checked]:border-primary-400 dark:has-[:checked]:bg-primary-950/50' => ! $isPaused,
                                    'cursor-not-allowed border-gray-200 bg-gray-50 opacity-60 dark:border-gray-800 dark:bg-gray-800/30' => $isPaused,
                                ])>
                                    <input
                                        type="radio"
                                        name="team_id"
                                        value="{{ $team->getKey() }}"
                                        form="authorizeForm"
                                        required
                                        @disabled($isPaused)
                                        @checked($team->getKey() === $selectedTeamId)
                                        class="size-4 shrink-0 accent-primary"
                                    >
                                    <span class="min-w-0 flex-1 text-sm font-medium break-words text-gray-900 dark:text-white">{{ $team->name }}</span>
                                    {{-- The card is narrower than the `sm` breakpoint, so these badges sit
                                         inline on desktop and drop to their own line on a phone rather than
                                         squeezing the workspace name into one word per line. --}}
                                    @if($isPaused)
                                        <span class="w-full pl-7 text-xs font-medium text-red-600 sm:w-auto sm:pl-0 sm:text-right dark:text-red-400">{{ __('mcp.consent.workspace.paused') }}</span>
                                    @elseif($team->personal_team)
                                        <span class="w-full pl-7 text-xs text-gray-500 sm:w-auto sm:pl-0 sm:text-right dark:text-gray-400">{{ __('mcp.consent.workspace.personal') }}</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>

                        @if($teams->count() === count($pausedTeamIds))
                            <p class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs leading-relaxed text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
                                {{ __('mcp.consent.workspace.all_paused') }}
                            </p>
                        @endif
                    </div>

                    <!-- Permissions -->
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('mcp.consent.permissions.heading') }}</h2>
                        <p class="mt-1 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                            {{ __('mcp.consent.permissions.description') }}
                        </p>

                        <ul class="mt-3 space-y-3">
                            <li class="flex items-start gap-3">
                                <span class="mt-px flex size-6 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                    <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0Z"/>
                                    </svg>
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ __('mcp.consent.permissions.read.title') }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ __('mcp.consent.permissions.read.description') }}</span>
                                </span>
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="mt-px flex size-6 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                    <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.86 4.49a2.1 2.1 0 1 1 2.97 2.97L8.4 18.9l-3.9.98.98-3.9L16.86 4.49Z"/>
                                    </svg>
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ __('mcp.consent.permissions.write.title') }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ __('mcp.consent.permissions.write.description') }}</span>
                                </span>
                            </li>
                            <li class="flex items-start gap-3">
                                <span class="mt-px flex size-6 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-400">
                                    <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                                    </svg>
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ __('mcp.consent.permissions.delete.title') }}</span>
                                    <span class="mt-0.5 block text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ __('mcp.consent.permissions.delete.description') }}</span>
                                </span>
                            </li>
                        </ul>

                        <p class="mt-4 rounded-lg bg-gray-50 px-3 py-2 text-xs leading-relaxed text-gray-500 dark:bg-gray-800/40 dark:text-gray-400">
                            {{ __('mcp.consent.permissions.excluded') }}
                        </p>
                    </div>
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
                    <button type="submit" @disabled($teams->count() === 0 || $teams->count() === count($pausedTeamIds)) class="inline-flex h-10 w-full items-center justify-center gap-2 whitespace-nowrap rounded-lg bg-primary px-4 text-sm font-medium text-white transition-colors hover:bg-primary-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:pointer-events-none disabled:opacity-50" id="authorizeButton">
                        <svg id="loadingSpinner" class="hidden size-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>

                        <span id="authorizeText">{{ __('mcp.consent.actions.authorize') }}</span>
                    </button>
                </form>
            </div>
        </div>

        <p class="mt-4 text-center text-xs text-gray-500 dark:text-gray-400">
            {{ __('mcp.consent.revoke_hint') }}
        </p>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('authorizeForm');
        const button = document.getElementById('authorizeButton');
        const authorizeText = document.getElementById('authorizeText');
        const loadingSpinner = document.getElementById('loadingSpinner');

        form.addEventListener('submit', function(e) {
            // Show loading state...
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
