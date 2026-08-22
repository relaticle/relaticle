// Block types this build knows how to paint. 'pending_action' is the proposal
// card. 'records_table' and 'record_card' are the read-tool `display_block`
// envelopes, carried on a message as `display_blocks` (derived on reload by
// ListConversationMessages and at stream end by
// ChatInterface::latestAssistantMessage()). A type this set does not know is
// dropped by displayBlocks() in transcript.js, which is what makes an older
// conversation carrying a retired block type render nothing rather than an
// empty frame.
const KNOWN_BLOCKS = new Set(['pending_action', 'records_table', 'record_card']);

export const isKnownBlock = (type) => KNOWN_BLOCKS.has(type);
