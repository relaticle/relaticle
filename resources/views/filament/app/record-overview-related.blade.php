<div class="fi-record-overview-related">
    @foreach ($sections as $section)
        <section class="fi-record-overview-section">
            <header class="fi-record-overview-related-header">
                <h3 class="fi-record-overview-related-title">
                    {{ $section['label'] }}
                    <span class="fi-record-overview-count">{{ $section['count'] }}</span>
                </h3>

                <div class="fi-record-overview-related-actions">
                    @if ($section['viewAll'])
                        <button type="button" class="fi-record-overview-view-all" x-on:click="{{ $section['viewAll'] }}">
                            {{ __('filament/record-page.overview.view_all') }}
                        </button>
                    @endif

                    @if ($section['create'])
                        <x-filament::icon-button
                            icon="lucide-plus"
                            color="gray"
                            size="sm"
                            :label="$section['createLabel']"
                            :tooltip="$section['createLabel']"
                            :x-on:click="$section['create']"
                            class="fi-record-overview-add"
                        />
                    @endif
                </div>
            </header>

            @if (filled($section['items']))
                <ul class="fi-record-overview-card fi-record-overview-list">
                    @foreach ($section['items'] as $item)
                        <li>
                            <button
                                type="button"
                                class="fi-record-overview-item"
                                x-on:click="{{ $item['open'] }}"
                            >
                                @if ($item['author'])
                                    <x-filament::avatar :src="$item['author']->getFilamentAvatarUrl()" :alt="$item['author']->name" size="xs" class="fi-record-overview-item-avatar" />
                                @else
                                    <x-filament::icon :icon="$item['icon']" class="fi-record-overview-item-icon" />
                                @endif
                                <span class="fi-record-overview-item-text">
                                    <span class="fi-record-overview-item-title">{{ $item['title'] }}</span>
                                    @if (filled($item['snippet']))
                                        <span class="fi-record-overview-item-snippet">{{ $item['snippet'] }}</span>
                                    @endif
                                </span>

                                @if ($item['assignee'])
                                    <span class="fi-record-overview-item-person">
                                        <x-filament::avatar :src="$item['assignee']->getFilamentAvatarUrl()" :alt="$item['assignee']->name" size="xs" />
                                        <span class="truncate">{{ $item['assignee']->name }}</span>
                                    </span>
                                @endif

                                @if ($item['due'])
                                    <span @class(['fi-record-due', "fi-record-due-{$item['due']['tone']}"])>
                                        <x-filament::icon icon="lucide-calendar" class="size-3.5" />
                                        {{ $item['due']['label'] }}
                                    </span>
                                @endif

                                @if ($item['time'])
                                    <span class="fi-record-overview-item-meta">{{ $item['time'] }}</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach
</div>
