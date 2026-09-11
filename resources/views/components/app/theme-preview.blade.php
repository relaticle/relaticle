@props(['mode'])

@php
    $panes = $mode === 'system' ? ['light', 'dark'] : [$mode];
@endphp

<span class="flex h-24 w-full overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
    @foreach($panes as $pane)
        <span @class([
            'flex flex-1 gap-1.5 p-2',
            'bg-gray-50' => $pane === 'light',
            'bg-gray-900' => $pane === 'dark',
        ])>
            <span class="flex w-1/3 flex-col gap-1">
                @for($i = 0; $i < 4; $i++)
                    <span @class([
                        'h-1.5 rounded-full',
                        'w-full' => $i === 0,
                        'w-3/4' => $i !== 0,
                        'bg-gray-300' => $pane === 'light',
                        'bg-gray-700' => $pane === 'dark',
                    ])></span>
                @endfor
            </span>

            <span @class([
                'flex flex-1 flex-col gap-1 rounded p-1.5',
                'bg-white' => $pane === 'light',
                'bg-gray-800' => $pane === 'dark',
            ])>
                @for($i = 0; $i < 5; $i++)
                    <span @class([
                        'h-1.5 rounded-full',
                        'w-full' => $i % 2 === 0,
                        'w-2/3' => $i % 2 !== 0,
                        'bg-gray-200' => $pane === 'light',
                        'bg-gray-700' => $pane === 'dark',
                    ])></span>
                @endfor
            </span>
        </span>
    @endforeach
</span>
