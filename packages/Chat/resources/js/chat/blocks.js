// Block-renderer registry: maps a transcript block `type` string to the DOM
// template id that renders it. The transcript consults this before rendering
// a block; unregistered types render nothing, silently.
//
// 'pending_action' is the proposal card. 'records_table' and 'record_card' are
// the read-tool `display_block` envelopes, carried on a message as
// `display_blocks` (derived on reload by ListConversationMessages and at
// stream end by ChatInterface::latestAssistantMessage()). A block whose type
// this file does not know is dropped by displayBlocks() in transcript.js,
// which is what makes an older conversation carrying a retired block type
// render nothing rather than an empty frame.
const registry = new Map();

export const registerBlock = (type, templateRefId) => registry.set(type, templateRefId);
export const blockTemplate = (type) => registry.get(type) ?? null;

registerBlock('pending_action', 'chat-block-pending-action');
registerBlock('records_table', 'chat-block-records-table');
registerBlock('record_card', 'chat-block-record-card');
