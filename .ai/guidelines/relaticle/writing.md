# Writing

Everything this repo publishes is product surface: marketing pages, help and
docs content, blog posts, UI strings, lang files, commit subjects, and PR
bodies. Buyers read it. Search engines and AI assistants index it.

## No em-dashes

Never use an em-dash (U+2014) in copy, docs, comments, commits, or PRs.

The glyph is half the problem. The tell is the cadence it carries: a short
clause, the dash, then an appositive restating the clause.

    Bad:  Export anytime — your data is yours.
    Bad:  Export anytime, your data is yours.
    Good: Export anytime. Your data is yours.

Swapping the dash for a comma keeps the cadence and fixes nothing. Rewrite the
sentence. Two sentences usually, or a colon when the second half genuinely
explains the first. Never run a find-and-replace over the character.

Vary construction. A writer reaches for one rhythm now and then. A page that
reaches for it fifteen times reads as one template applied over and over,
whatever punctuation it wears.

`tests/Arch/ConventionsTest.php` enforces this across `app/`, `packages/`,
`resources/`, `lang/`, `config/`, `database/`, `routes/`, and `bootstrap/`. One
exception is allowlisted: the standalone `'—'` string literal, used as a data
glyph for empty values in activity-log and custom-field diffs. Never as prose
punctuation.

One trap when rewriting: a colon is the natural replacement, but a bare `: `
inside an unquoted YAML front-matter value in
`packages/Documentation/resources/content` throws a ParseException that 500s
every help and docs page, not just that file. Use a period or a comma there.

## House style

- One idea per sentence. 25 words maximum. Active voice.
- Same term for the same thing every time. Lead with the answer.
- Cut every word that does no work. Write person to person, not corporate.
- No emojis in product copy, docs, or commits.
