@php
    $roleKeys = array_keys($matrix[array_key_first($matrix)] ?? []);
@endphp

<div class="overflow-x-auto">
    <table class="w-full text-sm" data-role-matrix>
        <thead>
            <tr class="border-b border-gray-200 dark:border-white/10">
                <th scope="col" class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">
                    <span class="sr-only">{{ __('workspaces.role_matrix.capability_column') }}</span>
                </th>
                @foreach ($roleKeys as $roleKey)
                    <th scope="col" class="px-3 py-2 text-center font-medium text-gray-700 dark:text-gray-200">
                        {{ __("workspaces.roles.{$roleKey}.label") }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($matrix as $capabilityValue => $roles)
                <tr class="border-b border-gray-100 dark:border-white/5">
                    <th scope="row" class="px-3 py-2 text-left font-normal text-gray-700 dark:text-gray-200">
                        {{ \App\Enums\WorkspaceCapability::from($capabilityValue)->label() }}
                    </th>
                    @foreach ($roleKeys as $roleKey)
                        <td class="px-3 py-2 text-center" data-capability="{{ $capabilityValue }}" data-role="{{ $roleKey }}">
                            @if ($roles[$roleKey])
                                <x-heroicon-o-check class="mx-auto h-4 w-4 text-success-600 dark:text-success-400" />
                                <span class="sr-only">{{ __('workspaces.role_matrix.granted') }}</span>
                            @else
                                <x-heroicon-o-minus class="mx-auto h-4 w-4 text-gray-300 dark:text-gray-600" />
                                <span class="sr-only">{{ __('workspaces.role_matrix.not_granted') }}</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
        <a
            href="{{ url()->getPublicUrl(route('help.show', ['category' => 'workspace', 'slug' => 'manage-members-and-roles'], false)) }}"
            target="_blank"
            class="text-primary-600 hover:underline dark:text-primary-400"
        >
            {{ __('workspaces.actions.compare_roles_help_link') }}
            <span class="sr-only">{{ __('workspaces.role_matrix.opens_in_new_tab') }}</span>
        </a>
    </p>
</div>
