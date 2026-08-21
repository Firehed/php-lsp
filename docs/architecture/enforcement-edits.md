# Enforcement edits: tighten, loosen, lateral

    Status:   Policy
    Applies:  phpstan.neon, deptrac.yaml, tests/Architecture/*, the two baselines, bin/, .github/, .claude/

The M×N rules (RFC 1 §8.1) are lists.
A list edit has one of three meanings, and only the first is open to an implementer.
A reviewer classifies every edit to the files above with this table before reading anything else in the diff.

## The table

| Edit | Meaning | Who may make it |
|---|---|---|
| Add a function, class, enum, or implementation to a deny set or confined set | Tighten | Anyone. The baseline may grow in the same PR, by the entries the new check reports and no others. |
| Remove a baseline entry | Tighten | Anyone. |
| Remove an edge from the layer ruleset | Tighten | Anyone. |
| Remove a path from an allowlist | Tighten | Anyone. |
| Add a check, rule, test, or layer | Tighten | Anyone. |
| Add a deny entry or rule together with its allowlist | Tighten, when every allowlist path is one file that uses the denied thing today (`tests/*` is the one directory glob). A directory glob, or a file with no use, is a Loosen hidden inside a Tighten. | Anyone, under that condition. The reviewer greps each denied name in `src/`: the hits and the allowlist must be the same set, with the rest in the baseline. |
| Rename a path in an allowlist or a baseline entry | Lateral | Anyone, only when the same PR moves that file (git reports a rename) and the entry count does not change. |
| Rename a rule identifier or message, with the baseline rewritten to match | Lateral | Anyone, only when the total for that rule does not change. |
| Add a path to an allowlist | Loosen | The human only. |
| Remove a function, class, enum, or implementation from a deny set or confined set | Loosen | The human only. |
| Add an edge to the layer ruleset | Loosen | The human only. |
| Add an `excludePaths` or `ignoreErrors` entry, or an inline `@phpstan-ignore` for a `phpLsp.*` or `disallowed.*` identifier | Loosen | The human only. |
| Add a baseline entry for a check that existed before the PR | Loosen | The human only. |
| Skip or delete a test | Loosen | The human only. |
| Edit `bin/check-baseline-shrink`, a workflow under `.github/`, a skill under `.claude/`, or this document | Loosen | The human only. |

"The human only" means the human writes the edit, or names it in the slice row before the slice starts.
A PR body that explains why a Loosen edit was necessary does not make it allowed.

## Why a Loosen edit is never the implementer's call

A rule that is narrowed to keep CI green reports nothing, and a check that reports nothing reads the same as a check that is satisfied.
The baseline only records what a rule reports, so a loosened rule removes the violation from the record instead of freezing it.
That is the one failure the rules exist to prevent, one tier up.

## What a reviewer does with the table

- A Loosen row in the diff is a finding, always.
- A Lateral row is a finding unless the diff also contains the rename it depends on.
- A Tighten row that grows a baseline is correct when every new entry is reported by the added check; any other growth is a Loosen row in disguise.
- A prose change to a policy paragraph (build manifest, build procedure, a skill) is a Loosen row.
