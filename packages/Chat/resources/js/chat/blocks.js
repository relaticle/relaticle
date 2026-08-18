// Block-renderer registry: maps a transcript block `type` string to the DOM
// template id that renders it. The transcript consults this before rendering
// a block; unregistered types render nothing, silently.
//
// Seeded here with the one block type that exists today ('pending_action',
// the existing proposal card). A later phase adds the `display_block`
// envelope server-side and registers 'record_card' / 'records_table' by
// calling registerBlock() again here, with no further transcript changes.
const registry = new Map();

export const registerBlock = (type, templateRefId) => registry.set(type, templateRefId);
export const blockTemplate = (type) => registry.get(type) ?? null;

registerBlock('pending_action', 'chat-block-pending-action');
