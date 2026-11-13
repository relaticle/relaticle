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

## Record names

Copy calls a record what the product calls it: company, person, opportunity, task, note.
The glossary and the words it retired are in `architecture.md`, under Business language.

"People" is also the plain word for humans. A possessive in front of it reads as the
reader's own staff.

    Bad:  Relaticle brings your people, companies and sales pipeline together.
    Good: Relaticle keeps the people and companies you sell to in one CRM.

Say whose they are after the noun, or use the singular: "every company, person, and
opportunity". `tests/Arch/ConventionsTest.php` fails `your people` in published copy.

Give "people" one meaning per sentence. When the record and the humans who use the
product meet, the humans are "you" or "your team". No test reads for this.

## The assistant and the MCP server

The built-in assistant has a name, `config('chat.assistant_name')`. The first mention on a
page is "Rela, the built-in AI assistant". After that it is "Rela". "AI chat" is not a name
for it. External agents reach Relaticle through "the MCP server", and copy names Claude and
ChatGPT where there is room, because a buyer knows those and may not know MCP.

Pitch copy states no tool count and no field type count. It says what an agent or a team
can do. The MCP guide's tool reference is the one place that counts tools.

    Bad:  Explore the AI assistant and 39 MCP tools
    Good: Explore Rela and the MCP server for Claude and ChatGPT

`tests/Arch/ConventionsTest.php` fails "AI chat" in published copy, and fails the MCP guide
when its count differs from the tools `RelaticleServer` registers.
`packages/Cloud/tests/Feature/Marketing/PublicPagesTest.php` fails a marketing page that quotes a count.

## House style

- One idea per sentence. 25 words maximum. Active voice.
- Same term for the same thing every time. Lead with the answer.
- Cut every word that does no work. Write person to person, not corporate.
- No emojis in product copy, docs, or commits.
