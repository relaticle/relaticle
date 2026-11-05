import { Extension } from '@tiptap/core'
import { TaskItem } from '@tiptap/extension-list/task-item'
import { TaskList } from '@tiptap/extension-list/task-list'

export default Extension.create({
    name: 'checklist',

    addExtensions() {
        return [TaskList, TaskItem.configure({ nested: false })]
    },
})
