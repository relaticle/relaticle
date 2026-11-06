import { Extension } from '@tiptap/core'
import { TaskItem } from '@tiptap/extension-list/task-item'
import { TaskList } from '@tiptap/extension-list/task-list'

const checkboxLabel = (node) => {
    const labels = window.filamentData ?? {}

    return node.textContent ? labels.checklistItemLabel?.replace(':item', node.textContent) : labels.checklistEmptyItemLabel
}

export default Extension.create({
    name: 'checklist',

    addExtensions() {
        return [TaskList, TaskItem.configure({ nested: false, a11y: { checkboxLabel } })]
    },
})
