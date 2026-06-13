{{-- Editable proposal field row. Scope vars: `action` (proposal), `editField`
     ({code,label,kind,value,options?,required}). Binds into action.edit.values
     (and action.edit.linkText for link). Edit-mode mirror of _proposal-field. --}}
<div class="flex flex-col gap-1">
    <label
        :for="'pf-' + action.pending_action_id + '-' + (action.edit?.index ?? 's') + '-' + editField.code"
        class="text-xs font-medium text-gray-600 dark:text-gray-400"
    >
        <span x-text="editField.label"></span><span x-show="editField.required" class="text-red-500" aria-hidden="true">&nbsp;*</span>
    </label>

    <template x-if="editField.kind === 'text'">
        <input type="text"
            :id="'pf-' + action.pending_action_id + '-' + (action.edit?.index ?? 's') + '-' + editField.code"
            x-model="action.edit.values[editField.code]"
            class="block w-full rounded-md border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
    </template>

    <template x-if="editField.kind === 'textarea'">
        <textarea
            :id="'pf-' + action.pending_action_id + '-' + (action.edit?.index ?? 's') + '-' + editField.code"
            x-model="action.edit.values[editField.code]" rows="3"
            class="block w-full resize-y rounded-md border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
    </template>

    <template x-if="editField.kind === 'number'">
        <input type="number"
            :id="'pf-' + action.pending_action_id + '-' + (action.edit?.index ?? 's') + '-' + editField.code"
            x-model="action.edit.values[editField.code]"
            class="block w-full rounded-md border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
    </template>

    <template x-if="editField.kind === 'date'">
        <input type="date"
            :id="'pf-' + action.pending_action_id + '-' + (action.edit?.index ?? 's') + '-' + editField.code"
            x-model="action.edit.values[editField.code]"
            class="block w-full rounded-md border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" />
    </template>

    <template x-if="editField.kind === 'toggle'">
        <label class="inline-flex cursor-pointer items-center gap-2">
            <input type="checkbox"
                :id="'pf-' + action.pending_action_id + '-' + (action.edit?.index ?? 's') + '-' + editField.code"
                x-model="action.edit.values[editField.code]"
                class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900" />
            <span class="text-xs text-gray-500 dark:text-gray-400" x-text="action.edit.values[editField.code] ? 'Yes' : 'No'"></span>
        </label>
    </template>

    <template x-if="editField.kind === 'select'">
        <select
            :id="'pf-' + action.pending_action_id + '-' + (action.edit?.index ?? 's') + '-' + editField.code"
            x-model="action.edit.values[editField.code]"
            class="block w-full rounded-md border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            <option value="">—</option>
            <template x-for="opt in (editField.options || [])" :key="opt.id">
                <option :value="opt.id" x-text="opt.label"></option>
            </template>
        </select>
    </template>

    <template x-if="editField.kind === 'multiselect'">
        <div class="flex flex-col gap-1 rounded-md border border-gray-200 bg-white p-2 dark:border-gray-600 dark:bg-gray-900">
            <template x-if="!(editField.options || []).length">
                <span class="text-xs text-gray-400">No options</span>
            </template>
            <template x-for="opt in (editField.options || [])" :key="opt.id">
                <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-gray-900 dark:text-gray-100">
                    <input type="checkbox" :value="opt.id"
                        :checked="(action.edit.values[editField.code] || []).includes(String(opt.id))"
                        x-on:change="setMultiselect(action, editField.code, opt.id, $event.target.checked)"
                        class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900" />
                    <span x-text="opt.label"></span>
                </label>
            </template>
        </div>
    </template>

    <template x-if="editField.kind === 'link'">
        <div class="flex flex-col gap-1">
            <textarea
                :id="'pf-' + action.pending_action_id + '-' + (action.edit?.index ?? 's') + '-' + editField.code"
                x-model="action.edit.linkText[editField.code]" rows="2" placeholder="One URL per line"
                class="block w-full resize-y rounded-md border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
            <span class="text-[11px] text-gray-400">One URL per line.</span>
        </div>
    </template>
</div>
