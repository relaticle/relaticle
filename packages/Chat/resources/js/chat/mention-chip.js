// One class string for an @mention chip, shared by the two places that
// render one: chat-editor.js (live, while composing) and transcript.js
// (read-only, once a message with a mention has been sent). Keeping this in
// one place is the whole point: the two used to carry independently
// hand-copied strings that had already drifted from the rest of the chip
// spec (see the "dense" pill variant used elsewhere in the transcript).
export const MENTION_CHIP_CLASS = 'inline-flex items-center rounded-full bg-primary-100 px-2 py-0.5 text-[length:var(--text-micro)] font-medium text-primary-800 dark:bg-primary-900/30 dark:text-primary-200';
