<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div class="text-sm mb-4">
        {{ __('profile.sections.browser_sessions.notice') }}
    </div>

    @if (count($sessions) > 0)
        <div class="space-y-6">
            <!-- Other Browser Sessions -->
            @foreach ($sessions as $session)
                <div class="flex items-center">
                    <div>
                        @if ($session->agent->isDesktop())
                            <x-filament::icon icon="lucide-monitor" class="w-8 h-8 "/>
                        @else
                            <x-filament::icon icon="lucide-smartphone" class="w-8 h-8 "/>
                        @endif
                    </div>

                    <div class="ms-3">
                        <div class="text-sm">
                            {{ $session->agent->platform() ?: __('profile.sections.browser_sessions.labels.unknown_device') }}
                            - {{ $session->agent->browser() ?: __('profile.sections.browser_sessions.labels.unknown_device') }}
                        </div>

                        <div>
                            <div class="text-xs text-gray-500">
                                {{ $session->ip_address }},

                                @if ($session->is_current_device)
                                    <span
                                        class="font-semibold">{{ __('profile.sections.browser_sessions.labels.current_device') }}</span>
                                @else
                                    {{ __('profile.sections.browser_sessions.labels.last_active') }} {{ $session->last_active }}
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-dynamic-component>
