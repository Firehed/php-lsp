# End-to-end tests

These tests start `bin/php-lsp` as a child process and talk to it over stdio, as an editor would.
They are the only tests that exercise the entry point and the real wiring.

Nothing in this directory may depend on `src/`.
The client is the oracle, so it must survive any refactor of `src/`.

```bash
composer e2e                    # run this suite
UPDATE_GOLDENS=1 composer e2e   # record transcripts
```

## Scripts

A script is a PHP file in `scripts/` that returns a `Script`: a project and a list of steps.
`ScriptTest` runs every script and compares the conversation to the `.json` transcript beside it.

- `project` is a directory holding `composer.json`, `composer.lock`, and source code, named as a path from the repository root.
  Run `composer install` in it before the suite.
  The server takes its project from its working directory.
- `Open`, `Type`, and `Ask` are the steps.
  Each names a file relative to the project and a place in it by marker.
- `CursorMarker` is the position just before a `/*|name*/` marker.
- `SymbolMarker` is the symbol on a line ending in `//hover:name`.
- `Type` inserts a short literal fragment, as a person would type it.
  This is the one exception to the no-inline-PHP rule in `CONTRIBUTING.md`.

Write a hand-written test, like `LifecycleTest`, only for behavior a script cannot express.

## Transcripts

A transcript is every message sent and received, in wire order.
Document text in sent messages is replaced with `…`, and the project path with `{project}`.

A first recording asserts nothing by itself.
Read it before committing it, and review every later change to it as a diff.

Transcripts are regression checks, not coverage.
Every test here is `#[CoversNothing]`, and coverage cannot see the child process anyway.

## Constraints

- Built-in symbols come from the PHP running the server (#401), so they vary across the CI matrix.
  A transcript belongs to a script, never to a PHP version.
  Keep built-in classes, functions, and their members out of a script's output until #401 is fixed.
  Every enum has a built-in ancestor.
- The server matches documents by their physical path.
  Build every URI from the resolved project root, as `Session` does.
- A PHP warning from the server corrupts the protocol stream and fails the test.
  That is intended; fix the server, not the test.

## Where a test belongs

- Behavior of the composed server, as an editor sees it: a script here.
- Behavior of one composite: an integration test that constructs the composite and its members directly.
- Anything else: a unit test that constructs what it needs directly.

No test uses the dependency container.

## Migrating a test

1. Confirm the test's output stays clear of built-in symbols.
2. Write a script per case, reusing existing fixtures and markers.
3. Record the transcript and check it against what the original test asserts.
4. Whether the original is deleted is decided per migration, in review.
5. Before deleting an original, run `composer unit -- --coverage-text` before and after.
   Report the difference in the pull request, and cover any lines it loses with unit tests.
