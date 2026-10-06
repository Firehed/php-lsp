# End-to-end tests

These tests start `bin/php-lsp` as a child process and talk to it over stdio, as an editor would.
They are the only tests that exercise the entry point and the real wiring.

Nothing in this directory may depend on `src/`.
The client is the oracle, so it must survive any refactor of `src/`.

```bash
composer e2e                    # run this suite
UPDATE_GOLDENS=1 composer e2e   # record transcripts
```

## Layout

- `Client/` starts the server and speaks the protocol. Scripts never use it directly.
- `Session/` holds `Script`, what a script file returns, and `Session`, which turns steps into messages.
- `Step/`, `Marker/`, and `Expectation/` are what scripts are written in.
- `Result/` holds answers decoded from the wire, which expectations check.

## Scripts

A script file is a PHP file in `scripts/` that returns a `Script`: a project and a list of steps.
A file may instead return several scripts keyed by case name, when cases differ by only a marker or an expectation.
Each case is its own test, against its own server.
`ScriptTest` runs every case, and its request steps check what the server answers.

A script file declares `namespace Firehed\PhpLsp\Tests\EndToEnd;` and names types relative to it, such as `Step\Open` and `Expectation\LandsOn`.

- `project` is a directory holding `composer.json`, `composer.lock`, and source code, named as a path from the repository root.
  Run `composer install` in it before the suite.
  Each script runs against a throwaway copy, which becomes the server's working directory.
- `capabilities` is what the client declares in `initialize`.
  Declare watched-file support in any script that changes files on disk.
- `Open`, `Type`, and `Close` are what a person does in the editor.
  Open only files a person would have open; the server finds the rest on its own.
- `Copy` and `Delete` change the disk as another program would, and the server is not told.
  `Copy` puts a prepared variant in place; variants live outside the autoload paths, such as `DiskChange/` in the fixture project.
- `ReportChanges` is the client's file watcher reporting what changed, in one notification.
- `Definition`, `Hover`, `SignatureHelp`, and `Complete` are requests.
  Each decodes the answer, failing on a malformed one, and checks it against `expect:`: one expectation, or a list that must all hold.
- Definition expectations are `LandsOn(file, line, column)`, where the column is optional, and `NoAnswer()`.
  Lines and columns are 1-based, as the file reads.
- Hover expectations are `Shows(...)` and `Hides(...)`, fragments of the content, `Formatted(MarkupKind)`, and `NoAnswer()`.
- Signature help expectations are `SignatureShows(...)` and `DocumentationShows(...)` on the active signature, `ActiveParameter(index)`, `SignatureCount(n)`, and `NoAnswer()`.
- Completion expectations are `Offers(...)`, every label present in any order, `Withholds(...)`, no label present in a complete list, and `Documents(label, text)`, one item with that label and exactly that documentation.
- Steps name files relative to the project, and places in them by marker.
- `CursorMarker` is the position just before a `/*|name*/` marker.
- `SymbolMarker` is the symbol on a line ending in `//hover:name`.
- `VariableMarker` is the last `$var` on a line ending in `//jtd:name var`.
  A cursor marker cannot sit inside a variable name without breaking the parse.
- `Type` inserts a short literal fragment, as a person would type it.
  This is the one exception to the no-inline-PHP rule in `CONTRIBUTING.md`.

A failure names the case and the step by number.
Write a hand-written test, like `LifecycleTest`, only for behavior a script cannot express.

## Transcripts

A script in `transcripts/` also locks its whole conversation to the `.json` transcript beside it: every message sent and received, in wire order.
Use one only where the exchange itself is the point, such as the `initialize` result or an open, change, and ask sequence.
A transcript file returns exactly one `Script`.
Document text in sent messages is replaced with `…`, and the project path with `{project}`.

A first recording asserts nothing by itself.
Read it before committing it, and review every later change to it as a diff.

Scripts are behavior checks, not coverage.
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
   Drop any open the original needed only for its own wiring.
3. Turn each of the original test's assertions into an expectation, and see each fail once before trusting it.
   Check that each expectation would reject a plausible wrong answer, not just an empty one.
4. Whether the original is deleted is decided per migration, in review.
5. Before deleting an original, run `composer unit -- --coverage-text` before and after, and report the difference in the pull request.
   Cover lost lines with unit tests where possible.
   Name any line that only the end-to-end tier still runs; those are approved case by case.
