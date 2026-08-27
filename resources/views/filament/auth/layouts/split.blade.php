@php
    use App\Filament\Pages\Auth\Register;

    $isRegistration = $livewire instanceof Register;
    $renderHookScopes = $livewire?->getRenderHookScopes();
@endphp

<x-filament-panels::layout.base :livewire="$livewire">
    <div
        class="min-h-screen bg-white dark:bg-gray-950 lg:grid lg:grid-cols-[minmax(0,1.06fr)_minmax(31rem,0.94fr)]"
        data-auth-layout="split"
    >
        <a href="#fi-main-content" class="fi-skip-link fi-sr-only">
            {{ __('filament-panels::layout.skip_to_content.label') }}
        </a>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIMPLE_LAYOUT_START, scopes: $renderHookScopes) }}

        <aside
            aria-label="{{ __('Relaticle overview') }}"
            class="relative hidden min-h-screen overflow-hidden border-r border-gray-200/80 bg-gray-50 px-10 py-9 lg:flex lg:flex-col xl:px-16 xl:py-12 dark:border-white/[0.08] dark:bg-gray-950"
        >
            <div
                class="absolute inset-0 bg-[linear-gradient(to_right,rgba(0,0,0,0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgba(0,0,0,0.025)_1px,transparent_1px)] bg-[size:3rem_3rem] [mask-image:radial-gradient(ellipse_80%_70%_at_45%_45%,black_20%,transparent_100%)] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.03)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.03)_1px,transparent_1px)]"
                aria-hidden="true"
            ></div>
            <div class="absolute left-1/3 top-1/3 size-96 rounded-full bg-primary-500/[0.06] blur-3xl dark:bg-primary-500/[0.1]" aria-hidden="true"></div>

            <a href="{{ url('/') }}" class="relative z-10 flex w-fit items-center gap-2.5 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary-500">
                <img src="{{ asset('brand/logomark.svg') }}" alt="" class="size-7">
                <span class="font-display text-lg font-bold tracking-tight text-gray-950 dark:text-white">Relaticle</span>
            </a>

            <div class="relative z-10 my-auto py-10 xl:py-12">
                <h2 class="max-w-2xl font-display text-[2.5rem] leading-[1.08] tracking-[-0.035em] xl:text-[3.25rem]">
                    <span class="block font-normal text-gray-500 dark:text-gray-400">
                        {{ $isRegistration ? __('Your CRM for people') : __('Your relationships, context,') }}
                    </span>
                    <span class="mt-1.5 block font-extrabold text-gray-950 dark:text-white">
                        {{ $isRegistration ? __('and AI-powered work.') : __('and next steps. Together.') }}
                    </span>
                </h2>

                <p class="mt-5 max-w-xl text-[15px] leading-7 text-gray-500 xl:text-base dark:text-gray-400">
                    {{ $isRegistration
                        ? __('Start with a calm, flexible workspace your whole team can shape. Add Rela or any MCP agent when you are ready.')
                        : __('Return to one clear workspace for your team, pipeline, notes, and the work that needs attention next.') }}
                </p>

                <div class="relative mt-9 w-[calc(100%+2rem)] max-w-3xl xl:mt-11 xl:w-[calc(100%+3rem)]" aria-hidden="true">
                    <div class="absolute -inset-6 -z-10 rounded-[2rem] bg-primary-500/[0.08] blur-3xl dark:bg-primary-500/[0.14]"></div>
                    <div class="overflow-hidden rounded-2xl border border-gray-300/70 bg-white shadow-[0_24px_70px_-28px_rgba(17,24,39,0.28)] ring-1 ring-black/[0.02] dark:border-white/[0.12] dark:bg-neutral-950 dark:shadow-black/50 dark:ring-white/[0.04]">
                        <div class="flex h-10 items-center justify-between border-b border-gray-200/70 bg-gray-50 px-4 dark:border-white/[0.06] dark:bg-neutral-900">
                            <div class="flex gap-1.5">
                                <span class="size-2 rounded-full bg-gray-300 dark:bg-white/10"></span>
                                <span class="size-2 rounded-full bg-gray-300 dark:bg-white/10"></span>
                                <span class="size-2 rounded-full bg-gray-300 dark:bg-white/10"></span>
                            </div>
                            <span class="rounded-md bg-white px-2.5 py-1 text-[10px] font-medium text-gray-400 shadow-sm dark:bg-white/[0.04] dark:text-gray-500">app.relaticle.com</span>
                            <span class="w-8"></span>
                        </div>

                        <div class="h-[315px] overflow-hidden xl:h-[370px]">
                            <img
                                src="{{ asset('images/app-pipeline-preview.webp') }}"
                                alt=""
                                class="block w-[180%] max-w-none dark:hidden"
                                width="1440"
                                height="1116"
                            >
                            <img
                                src="{{ asset('images/app-pipeline-preview-dark.webp') }}"
                                alt=""
                                class="hidden w-[180%] max-w-none dark:block"
                                width="1440"
                                height="1116"
                            >
                        </div>
                    </div>
                </div>
            </div>

            <div class="relative z-10 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-medium text-gray-500 dark:text-gray-400">
                <span class="inline-flex items-center gap-1.5"><x-ri-check-line class="size-3.5 text-primary-600 dark:text-primary-400" />{{ __('Open source') }}</span>
                <span class="inline-flex items-center gap-1.5"><x-ri-check-line class="size-3.5 text-primary-600 dark:text-primary-400" />{{ __('Self-hostable') }}</span>
                <span class="inline-flex items-center gap-1.5"><x-ri-check-line class="size-3.5 text-primary-600 dark:text-primary-400" />{{ __('Human-first') }}</span>
            </div>
        </aside>

        <div class="relative flex min-h-screen items-center justify-center px-6 py-10 sm:px-10 lg:px-12 xl:px-16">
            <a href="{{ url('/') }}" class="absolute left-6 top-6 flex items-center gap-2.5 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary-500 lg:hidden">
                <img src="{{ asset('brand/logomark.svg') }}" alt="" class="size-7">
                <span class="font-display text-lg font-bold tracking-tight text-gray-950 dark:text-white">Relaticle</span>
            </a>

            <main id="fi-main-content" tabindex="-1" class="w-full max-w-lg">
                {{ $slot }}
            </main>
        </div>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::FOOTER, scopes: $renderHookScopes) }}
        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIMPLE_LAYOUT_END, scopes: $renderHookScopes) }}
    </div>
</x-filament-panels::layout.base>
