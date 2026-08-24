# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

Packages mirror the app's layout under `packages/<Name>/src` and
`packages/<Name>/resources`, so every row below covers both trees. A rule that
matched only `app/**` would silently skip `packages/Chat/src/Livewire`,
`packages/*/src/Models` and every package Blade view.

| Applies to | Rule file |
| --- | --- |
| app/Http/**, packages/*/src/Http/**, routes/**, packages/*/routes/** | .ai/rules/boost/http-routes.md |
| app/Livewire/**, packages/*/src/Livewire/**, resources/views/**, packages/*/resources/views/** | .ai/rules/boost/livewire-views.md |
| app/Models/**, packages/*/src/Models/** | .ai/rules/boost/models.md |
| tests/** | .ai/rules/boost/tests.md |
| packages/Chat/** | .ai/rules/chat.md |
