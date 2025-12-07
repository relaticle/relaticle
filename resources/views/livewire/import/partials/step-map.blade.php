<div class="space-y-6">
    {{-- Column Mapping Table --}}
    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        Field
                    </th>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        CSV Column
                    </th>
                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 w-16">
                        Status
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                @foreach ($this->importerColumns as $column)
                    @php
                        $columnName = $column->getName();
                        $isRequired = $column->isMappingRequired();
                        $isMapped = !empty($columnMap[$columnName]);
                    @endphp
                    <tr @class([
                        'bg-danger-50 dark:bg-danger-950/30' => $isRequired && !$isMapped,
                    ])>
                        <td class="px-4 py-3 text-sm">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-gray-950 dark:text-white">
                                    {{ $column->getLabel() }}
                                </span>
                                @if ($isRequired)
                                    <x-filament::badge size="sm" color="danger">
                                        Required
                                    </x-filament::badge>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model.live="columnMap.{{ $columnName }}">
                                    <option value="">Select column</option>
                                    @foreach ($csvHeaders as $header)
                                        <option value="{{ $header }}">{{ $header }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($isMapped)
                                <x-filament::icon
                                    icon="heroicon-s-check-circle"
                                    class="mx-auto h-5 w-5 text-success-500"
                                />
                            @elseif ($isRequired)
                                <x-filament::icon
                                    icon="heroicon-s-exclamation-circle"
                                    class="mx-auto h-5 w-5 text-danger-500"
                                />
                            @else
                                <x-filament::icon
                                    icon="heroicon-o-minus-circle"
                                    class="mx-auto h-5 w-5 text-gray-400 dark:text-gray-600"
                                />
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Missing Required Fields Warning --}}
    @if (!$this->hasAllRequiredMappings())
        <x-filament::section compact class="bg-warning-50 dark:bg-warning-950">
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-s-exclamation-triangle" class="h-5 w-5 text-warning-500 shrink-0" />
                <div class="min-w-0">
                    <p class="text-sm font-medium text-warning-800 dark:text-warning-200">Missing Required Mappings</p>
                    <p class="text-sm text-warning-700 dark:text-warning-300">
                        {{ implode(', ', $this->getMissingRequiredMappings()) }}
                    </p>
                </div>
            </div>
        </x-filament::section>
    @endif

    {{-- Sample Data Preview --}}
    @if ($this->hasAllRequiredMappings())
        <x-filament::section compact>
            <x-slot name="heading">Sample Data Preview</x-slot>
            <div class="overflow-x-auto -mx-4 sm:-mx-6">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            @foreach ($columnMap as $fieldName => $csvColumn)
                                @if ($csvColumn)
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        {{ $fieldName }}
                                    </th>
                                @endif
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($this->getSampleRows(3) as $row)
                            <tr>
                                @foreach ($columnMap as $fieldName => $csvColumn)
                                    @if ($csvColumn)
                                        <td class="px-4 py-2 text-gray-700 dark:text-gray-300 truncate max-w-[150px]">
                                            {{ $row[$csvColumn] ?? '' }}
                                        </td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    {{-- Navigation --}}
    <div class="flex justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
        <x-filament::button
            wire:click="previousStep"
            color="gray"
            icon="heroicon-m-arrow-left"
        >
            Back
        </x-filament::button>
        <x-filament::button
            wire:click="nextStep"
            :disabled="!$this->canProceedToNextStep()"
            icon="heroicon-m-arrow-right"
            icon-position="after"
        >
            Continue
        </x-filament::button>
    </div>
</div>
