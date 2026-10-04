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
  Each script runs against a throwaway copy, which becomes the server's working directory.
- `capabilities` is what the client declares in `initialize`.
  Declare watched-file support in any script that changes files on disk.
- `Open`, `Type`, `Close`, and `Ask` are what a person does in the editor.
  Each names a file relative to the project, and `Type` and `Ask` a place in it by marker.
- `Copy` and `Delete` change the disk as another program would, and the server is not told.
  `Copy` puts a prepared variant in place; variants live outside the autoload paths, such as `DiskChange/` in the fixture project.
- `ReportChanges` is the client's file watcher reporting what changed, in one notification.
- `CursorMarker` is the position just before a `/*|name*/` marker.
- `SymbolMarker` is the symbol on a line ending in `//hover:name`.
- `VariableMarker` is the last `$var` on a line ending in `//jtd:name var`.
  A cursor marker cannot sit inside a variable name without breaking the parse.
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

- Built-in symbols come from the PHP running the server (#401), so they can differ across the CI matrix.
  A transcript belongs to a script, never to a PHP version, so a script may show a built-in only when its output is the same on every version.
  CI runs every version in the matrix; a built-in script is not settled until it passes there.
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

1. Note which cases show built-in symbols; their scripts need a CI run before they are settled.
2. Write a script per case, reusing existing fixtures and markers.
3. Record the transcript and check it against what the original test asserts.
4. Whether the original is deleted is decided per migration, in review.
5. Before deleting an original, run `composer unit -- --coverage-text` before and after.
   Report the difference in the pull request, and cover any lines it loses with unit tests.
