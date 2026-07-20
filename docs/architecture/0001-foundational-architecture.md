# PHP-LSP-RFC 1: Foundational Architecture and Invariants

    Document:   PHP-LSP-RFC 1
    Category:   Architecture Requirements (Best Current Practice)
    Status:     Draft
    Target:     Language Server Protocol 3.17
    Date:       2026-07-19
    Supersedes: none
    Relates-To: docs issues #264, #265, #266; architecture invariants in CLAUDE.md

## Abstract

This document defines the foundational architecture of the PHP Language Server
and the invariants that all contributions MUST uphold. Its purpose is to keep the
cost of adding a feature proportional to that feature alone, rather than to the
number of existing features it interacts with. It names the axes along which the
system is expected to vary — new symbol kinds, new type forms, new data sources,
new target environments, and new protocol capabilities — and requires that each
be absorbed at a single extension point. It is a requirements and constraints
document; it is not a design for, nor a commitment to, any specific feature.

## Status of This Document

This is a Draft. It records architectural requirements that govern the codebase.
Normative statements use the keywords defined in Section 1.3. When this document
and inline code comments disagree, this document takes precedence and the code
SHOULD be corrected. Amendments are made by superseding RFC or by revision with
a bumped date.

## Table of Contents

    1. Introduction
       1.1. Background
       1.2. Scope and Non-Goals
       1.3. Requirements Language
       1.4. Normative References
    2. Terminology
    3. Architectural Model (Non-Normative)
       3.1. Axes of Variation
       3.2. Layering
    4. Invariants
       4.1. Handler Responsibility
       4.2. Symbol Discovery Authority
       4.3. Read/Write Segregation
       4.4. Separation of Positional and Knowledge Concerns
       4.5. Capability Predicates over Kind and Type Inspection
       4.6. Type Construction and Graph Traversal
       4.7. Environment-Parameterized Built-ins
       4.8. Protocol Capability Negotiation
       4.9. Position Encoding
       4.10. Client Conformance Defects
    5. Component Requirements
       5.1. Symbol Knowledge: Read Contract (SymbolSource)
       5.2. Symbol State: Write Contract (SymbolSink)
       5.3. Backend Substitutability and Caching Policy
       5.4. Session Capabilities
    6. Concurrency Model
    7. Extensibility Procedure
    8. Conformance
       8.1. Enforcement
    9. Robustness Considerations
    10. Rationale (Non-Normative)
    Appendix A. Axes of Variation Catalog
    Appendix B. Relationship to Existing Invariants and Issues
    Appendix C. LSP Feature and Version Reference

## 1. Introduction

### 1.1. Background

The server has twice encountered the same failure shape: a capability implemented
correctly for one case but not another, so that a feature "works on X but not Y."
The first occurrence was on the *handler x node-type* axis (issues #190, #253,
#256): each handler re-implemented "what is under the cursor," so a fix in one
handler did not reach the others. That axis was closed by routing all handlers
through a single resolution interface.

The same shape exists, unclosed, on other axes. Symbol lookup is answered by
several overlapping mechanisms with different coverage: classes resolve through a
tiered repository with project-wide reach, functions resolve only within an
already-parsed syntax tree, and global constants do not resolve at all.
Separately, the server does not read client capabilities at all, so every
response is shaped without regard to what the client declared it understands.

This document generalizes the lesson from the handler axis to every axis of
variation, so that the failure shape cannot regrow as the language and the
protocol evolve.

### 1.2. Scope and Non-Goals

This document governs the structure of symbol knowledge, type modeling, protocol
negotiation, and the concurrency model. It defines extension points and the rules
for using them.

It is NOT a plan for any feature. References to prospective language features
(e.g. value types, algebraic data types, function autoloading, generics) or
protocol features (e.g. diagnostics, semantic tokens) appear ONLY as tests of
extensibility. Nothing here schedules, scopes, or commits to implementing them.

### 1.3. Requirements Language

The key words "MUST", "MUST NOT", "REQUIRED", "SHALL", "SHALL NOT", "SHOULD",
"SHOULD NOT", "RECOMMENDED", "NOT RECOMMENDED", "MAY", and "OPTIONAL" in this
document are to be interpreted as described in BCP 14 [RFC2119] [RFC8174] when,
and only when, they appear in all capitals, as shown here.

### 1.4. Normative References

    [RFC2119]  Bradner, S., "Key words for use in RFCs to Indicate Requirement
               Levels", BCP 14, RFC 2119, March 1997.
    [RFC8174]  Leiba, B., "Ambiguity of Uppercase vs Lowercase in RFC 2119 Key
               Words", BCP 14, RFC 8174, May 2017.
    [LSP]      Microsoft, "Language Server Protocol Specification — 3.17",
               https://microsoft.github.io/language-server-protocol/specifications/lsp/3.17/specification/

All references to [LSP] are to version 3.17 unless stated otherwise. [LSP] is
organized by named sections rather than numbers; to stay robust against
subsection-title drift, citations name the containing part and, where applicable,
the request method (e.g. [LSP], "Base Protocol", `$/cancelRequest`). Exact
subsection titles are collected in Appendix C and were checked against the live
3.17 specification.

## 2. Terminology

- **Symbol**: A named, addressable program entity — a class-like, function,
  constant, or class member.
- **Class-like**: Any of class, interface, trait, or enum, and any future value
  or sum type that participates in member resolution (Section 4.5).
- **Symbol kind**: The coarse category of a symbol used for discovery. Discovery
  distinguishes only the categories that name resolution distinguishes; it does
  not distinguish flavours of class-like.
- **SymbolSource**: The single *read* abstraction through which symbol existence,
  metadata, definition location, and namespace enumeration are answered
  (Section 4.2). It is read-only by definition; the write side is the SymbolSink.
- **SymbolSink**: The sibling *write* abstraction through which document-lifecycle
  changes mutate symbol state (Section 5.2). A single object MAY implement both
  SymbolSource and SymbolSink, but each consumer depends only on the one it needs.
- **Backend**: A concrete provider of symbol knowledge behind the SymbolSource /
  SymbolSink (e.g. open documents, workspace-on-disk, vendored dependencies,
  language built-ins).
- **Lookup**: A query keyed by name — "resolve this symbol."
- **Enumeration**: A query keyed by namespace or prefix — "what exists here."
- **Positional analysis**: Determining what a cursor position denotes
  (node-at-offset, scope, member/call context). Document- and cursor-shaped.
- **Capability predicate**: A boolean query about a symbol's suitability for a
  position or operation (e.g. "is instantiable", "is throwable").
- **Target environment**: The PHP version and platform the *project* targets,
  which MAY differ from the runtime executing the server.
- **Session capabilities**: The immutable result of negotiating client and server
  capabilities at initialization (Section 5.4).

## 3. Architectural Model (Non-Normative)

This section is explanatory. The binding requirements are in Section 4 onward.

### 3.1. Axes of Variation

The system is expected to change along a small set of independent axes. A change
is "safe" when it moves exactly one axis and plugs into that axis's single
extension point; it is "unsafe" (an M×N change) when it forces edits across the
consumers of an axis. The catalog is in Appendix A. The axes are:

- **Data source** — where symbol knowledge comes from.
- **Symbol kind** — what categories of symbol exist.
- **Type form** — what shapes a type can take.
- **Target environment** — which language version/platform is assumed.
- **Protocol capability** — what the client understands and how output is shaped.
- **Position/intent** — where in the source a completion or resolution is
  requested.

Client-supplied configuration (initialization options, `workspace/configuration`)
is deliberately not a separate axis: version- and platform-like settings fold into
the target environment (Section 4.7), and behavior toggles into negotiated session
values (Sections 4.8, 5.4). There is no free-form settings store.

### 3.2. Layering

Three concerns are kept distinct:

1. **Positional layer** — answers "what is at this position." Document- and
   cursor-shaped. Owns node-at-offset, scope, and member/call-context detection,
   including any text-based resilience for incomplete input.
2. **Knowledge layer (SymbolSource)** — answers "what exists in the project and
   where." Has no concept of a cursor.
3. **Resolution glue** — turns a positional result into a resolved symbol by
   consulting the knowledge layer. Thin.

The positional layer poses questions to the knowledge layer; the knowledge layer
never depends on the positional layer.

## 4. Invariants

### 4.1. Handler Responsibility

LSP request handlers MUST be formatters. A handler MUST NOT parse documents,
locate nodes by position, detect node types, resolve types, or look up members.
A handler MUST extract protocol parameters, invoke a resolution or knowledge
service, and format the result for its response. (This restates and retains the
existing invariant established for the handler x node-type axis.)

### 4.2. Symbol Discovery Authority

All queries for symbol existence, definition location, symbol metadata, and
namespace enumeration MUST be answered through the SymbolSource abstraction.

Components MUST NOT, for these purposes, query a concrete index, repository,
autoload map, or reflection directly. Adding, removing, or changing *where*
symbols come from MUST be expressible as a change to a backend (Section 5.3)
with no change to consumers.

Both lookup and enumeration MUST be served by SymbolSource. They are distinct
operations and MUST NOT be collapsed into one another, but they MUST draw on the
same backends so that coverage is identical across them.

### 4.3. Read/Write Segregation

Read operations (SymbolSource) and document-lifecycle write operations
(SymbolSink) MUST be exposed as separate interfaces. A consumer that only reads
MUST depend only on SymbolSource. A single implementation MAY provide both.

There MUST be exactly one write path for symbol state — document changes and any
background indexing (Section 6) alike. A single document change MUST NOT update two
independent stores.

### 4.4. Separation of Positional and Knowledge Concerns

Positional analysis (Section 3.2, layer 1) MUST be separated from symbol
knowledge (layer 2). SymbolSource MUST NOT accept a cursor position, and MUST NOT
require callers to supply a parsed syntax tree in order to resolve a symbol by
name. Knowledge queries MUST be answerable from a name (and, where relevant, a
target environment) alone.

### 4.5. Capability Predicates over Kind and Type Inspection

A consumer MUST determine a symbol's suitability for a position or operation by
querying a capability predicate. A consumer MUST NOT branch on a concrete symbol
kind enumeration, and MUST NOT use `instanceof` against a concrete type
implementation, to make such a decision.

Consequently, introducing a new symbol kind or a new type form MUST NOT require
edits to consumers that operate through predicates and interfaces. A new
class-like kind that participates in member resolution MUST be reachable through
the same predicates and the same traversal as existing class-likes.

This invariant forbids branching on a *resolved* symbol's kind to decide its
suitability or behavior. It does NOT forbid routing a lookup by the kind implied
by *syntactic position*: the grammar usually fixes the expected kind (a name after
`new` or before `::` is a class-like; a name in call position is a function; a
bare name in constant position is a constant). Deriving that expected kind is the
positional layer's responsibility (Section 3.2), and the resolution glue MAY
dispatch to the matching per-kind lookup (Section 5.1) on that basis. Where a
position is genuinely kind-ambiguous, the kind-agnostic location query
(Section 5.1) MUST be used rather than an ad hoc scan that branches on each
candidate kind's result. This is the resolution of the apparent tension between
this section and the per-kind return types required by Section 5.1.

### 4.6. Type Construction and Graph Traversal

Type objects MUST be constructed through the type factory, from every input
source (syntax tree, reflection, and documentation annotations). Types MUST be
consumed through the type interface; consumers MUST NOT depend on a concrete type
implementation.

Type-graph traversal (the walk over used traits, parents, and interfaces) MUST
occur in exactly one place. Every member-kind lookup MUST follow the same edges.
(This restates and retains the existing single-traversal invariant.)

### 4.7. Environment-Parameterized Built-ins

Knowledge of built-in and platform-provided symbols MUST be parameterized by an
explicit target environment (Section 2) and MUST be confined to the backend that
provides it. A component MUST NOT assume that the runtime executing the server is
the project's target environment.

Symbol availability metadata (for example, the version in which a symbol was
introduced or deprecated) MUST be modeled as symbol metadata and surfaced through
a capability predicate or completion filter, not by ad hoc checks at call sites.

The target environment MAY change during a session (for example, via
`workspace/didChangeConfiguration`). A component MUST NOT assume it is fixed after
initialization. When it changes, every environment-dependent cache (Section 5.3)
MUST be invalidated or re-keyed by the new environment.

### 4.8. Protocol Capability Negotiation

Client capabilities ([LSP], "Server lifecycle", `initialize` →
ClientCapabilities) MUST be read during the `initialize` request and resolved once
into an immutable session-capabilities value (Section 5.4).

The server MUST NOT advertise a capability it does not implement. It MAY advertise
an implemented capability regardless of whether the client declared support, as
[LSP] permits: a client that did not ask for a feature simply will not invoke it,
and "the client cannot consume it" is not reliably derivable from a missing
capability (dynamic registration and defaults intervene). The binding rule is the
next paragraph — shape each *response* by declared client support, not the set of
advertised providers.

Any decision that shapes an outgoing message by client support (for example,
markup kind for hover contents per [LSP] "Language Features → Hover"; snippet
support per [LSP] "Language Features → Completion"; resolve support; diagnostic
tags) MUST query the session-capabilities value. A component MUST NOT re-inspect
the raw `initialize` parameters.

The absence of a client capability MUST resolve to a safe default that is the
value's own default state, not a special-cased branch at the point of use.
Minimal or older clients are therefore served by the default configuration and
require no dedicated code path.

Lifecycle state MUST be enforced per [LSP], "Server lifecycle": requests
received before `initialize` MUST be answered with `ServerNotInitialized`, and
requests received after `shutdown` MUST be answered with `InvalidRequest`.

### 4.9. Position Encoding

Position offsets MUST be treated per [LSP], "Basic JSON Structures" (`Position`),
under which `character` is measured in UTF-16 code units by default. A client
advertises the encodings it supports via the `general.positionEncodings` array;
the server selects one and returns it as the single `positionEncoding` server
capability (3.17). If the client offers none, the server MUST assume UTF-16.

Encoding conversion MUST occur at the transport/document boundary, into a single
negotiated internal representation. Interior components MUST operate only on that
representation and MUST NOT re-derive offsets against the wire encoding or assume a
`character` offset is a byte offset. (The internal representation is itself an
encoding; the requirement is that exactly one is used throughout the interior, not
that the interior is encoding-free.)

### 4.10. Client Conformance Defects

Capability negotiation (Section 4.8) covers what a client *declares*. It does not
cover a client that advertises a capability but mishandles it — a client defect,
not a missing capability. Such defects MUST NOT be assumed away by negotiation.

Where a client is known to mishandle a feature it advertises (for example, an
editor that ignores a completion item's `textEdit` range and inserts the raw
`newText`), the server SHOULD prefer the output form with the widest correct
support and SHOULD degrade defensively rather than rely on the advertised
capability. Any narrowly-scoped, per-client accommodation MAY be applied only as a
last resort, MUST be documented and isolated from feature logic, and MUST NOT leak
`if (client == X)` conditionals into resolution or knowledge code.

A running list of observed client defects and their accommodations SHOULD be
maintained (Appendix B).

## 5. Component Requirements

### 5.1. Symbol Knowledge: Read Contract (SymbolSource)

The read interface MUST provide, at minimum:

- Lookup by qualified name returning strongly typed metadata, per kind (e.g.
  class-like, function, constant). Return types MUST be concrete domain types,
  not a type-erased union.
- Kind-agnostic location of a symbol's definition, sufficient for
  go-to-definition and hover, without requiring full metadata construction.
- Enumeration of a namespace's child namespaces and directly declared symbols.
- Prefix search across the project, filterable by kind, for completion.

Names MUST be represented by typed identifiers, not bare strings (for example,
distinct identifier types for class, function, and constant names). Coverage MUST
be uniform across kinds: a query answerable for one kind MUST be answerable for
all kinds for which it is meaningful.

### 5.2. Symbol State: Write Contract (SymbolSink)

The SymbolSink write interface MUST be the sole means of mutating symbol state.
Its primary path is document lifecycle — open, update, and close operations keyed
by document identity — and any other producer of symbol state (for example,
background or parallel workspace indexing, Section 6) MUST write through the same
interface rather than a second store. On-disk changes to files that are not open in
the editor — an external edit, a branch checkout, or `workspace/didChangeWatchedFiles`
notifications where the client supports them (Section 4.8) — are a third such
producer: they MUST invalidate any cached or background-indexed workspace state for
the affected files through the same write path. A workspace backend that resolves
purely lazily from disk satisfies this by re-reading and needs no explicit
invalidation. Open-document state MUST take precedence over on-disk state for the
same symbol, including when the opened document is a vendored or otherwise
normally-cached file (Section 5.3).

### 5.3. Backend Substitutability and Caching Policy

Backends MUST be substitutable: each MUST satisfy the same contract, and a
backend that cannot answer a query MUST signal absence — an empty collection for an
enumeration, and `null` for a lookup. The project discourages but does not forbid
nullable types; here a nullable lookup result is the honest signal and a null
object would be worse, so a bare `null` is preferred. A backend MUST NOT raise an
error to signal "not found," and MUST NOT return a partial or approximate answer
presented as complete.

Backend *precedence* is fixed: for any symbol, an open-document answer MUST
override the workspace, vendored, and built-in backends. Opening a normally-cached
file — a vendored dependency, or any on-disk file — MUST route that file's symbols
through the open-document backend for as long as it is open, so that a user's
unsaved edits to a vendored file are honored and the cached answer is superseded.
On close — and on any external on-disk change to a cached file — the backend MUST
invalidate that file's cached entry and re-read from disk on the next query, so
that a saved edit is reflected and the pre-edit cached value is NOT restored.

Caching is a *policy per backend*, and MUST be expressed behind a replaceable
cache abstraction (for example, a PSR-6 / PSR-16 seam) rather than hard-coded
memoization, so that eviction, size bounds, or TTLs can be introduced later
without restructuring consumers. The default policy follows source stability:

- Open documents change on every keystroke and MUST NOT be cached; a stale answer
  here is a renamed symbol still being offered.
- Workspace-on-disk MAY be resolved lazily on demand and MAY be indexed in the
  background; any background work MUST be bounded (Section 6) and MUST report what
  it bounded or skipped rather than silently truncating.
- Language built-ins are fixed for a given target environment (Section 4.7) and
  SHOULD be cached, keyed by that environment; a change of target environment MUST
  invalidate or re-key the cache.
- Vendored dependencies are stable only while their files are unchanged on disk.
  They SHOULD be cached, but any on-disk change to a vendored file — an edit
  (including closing a file that was edited in the editor), a `composer` update, or
  a branch checkout — MUST invalidate that file's cached entry so the next read
  reflects disk.

Caching in every case is a default policy, not a guarantee of an unbounded cache; a
backend MAY apply eviction or size bounds through the cache abstraction.

Where a bound is applied to coverage (a cap, a skipped directory, a non-retried
failure), the server MUST make that omission observable rather than presenting
truncated results as complete.

### 5.4. Session Capabilities

Negotiation (Section 4.8) MUST produce a single immutable value that is
constructed once and injected into components that shape output. It MUST expose
named queries (for example, hover markup kind, snippet support, position
encoding) and MUST yield safe defaults for every capability the client did not
declare.

## 6. Concurrency Model

Two distinct mechanisms must not be conflated. PHP *Fibers* provide cooperative
concurrency on a single thread and therefore no parallelism: they do not
accelerate CPU-bound work such as parsing or resolution. The language *does* offer
true parallelism by other means — multiple processes (`pcntl_fork`, worker pools),
threads via extensions (ext-parallel), and libraries built on them
(amphp/parallel) — and can offload a hot path to native code (FFI, or a PHP
extension). The architecture neither requires nor precludes any of these.

The requirements distinguish the interactive hot path from background work:

- CPU-bound resolution and knowledge queries MUST remain correct under purely
  synchronous execution. Correctness MUST NOT depend on an event loop, a worker
  pool, or a native accelerator being present.
- For the single-request interactive hot path, throughput improvements SHOULD come
  first from caching and memoization; a concurrency runtime is not a substitute for
  the caches this codebase needs, and adopting one MUST NOT be a reason to defer
  them.
- Cooperative asynchrony (e.g. Fibers) MUST, if adopted, be confined to the
  transport, dispatch, and scheduling tier — for request cancellation ([LSP],
  "Base Protocol", `$/cancelRequest`), non-blocking dispatch, debounced
  server-initiated notifications, and cooperatively-yielding background work. It
  MUST NOT require the resolution interior to become asynchronous.
- True parallelism (separate processes/threads) and native acceleration (FFI or an
  extension) MAY be used for background work such as workspace indexing or a
  parsing hot path. When used: results MUST re-enter shared state through the
  SymbolSink write contract (Section 5.2); an accelerated component MUST sit behind
  its existing abstraction (e.g. the parser or type factory) so consumers are
  unchanged; and — because stock PHP shares no memory across processes — the cost
  of marshalling results across the boundary MUST be accounted for.
- An implementation MUST NOT assume optional runtime components are present. Fibers
  and FFI exist across all supported PHP versions and MAY be relied on;
  process-based parallelism (`pcntl`, `ext-parallel`) is not enabled by default and
  MUST be feature-detected at runtime, with a synchronous fallback when it is
  absent.
- Any long-running background task MUST NOT starve interactive requests, and MUST
  be cancelable.

## 7. Extensibility Procedure

To add capability along an axis (Appendix A), a contribution MUST use that axis's
single extension point and MUST NOT edit consumers of the axis:

- **New data source** → add a backend (Section 5.3). No consumer changes.
- **New symbol kind** → add the kind, its lookup, and its extraction. Consumers
  reach it through existing predicates and traversal (Sections 4.5, 4.6).
- **New type form** → add a type implementation and factory support (Section 4.6).
  Consumers reach it through the type interface.
- **New target-environment concern** → extend the environment parameter and the
  built-ins backend (Section 4.7).
- **New protocol capability** → extend session capabilities and the relevant
  handler; negotiate per Section 4.8.
- **New completion position/intent** → add a source and an intent mapping; the
  handler remains a coordinator (retains the existing completion-source
  invariant).

A contribution that cannot be expressed as one of the above SHOULD be treated as
a signal that an axis or extension point is missing, and SHOULD be raised as an
amendment to this document before implementation.

## 8. Conformance

A change conforms to this document if all of the following hold. The list is
exhaustive over the normative sections; each item names the section it checks.

1. No handler performs resolution or knowledge lookup (Section 4.1).
2. No symbol existence, location, metadata, or enumeration query bypasses
   SymbolSource (Section 4.2).
3. Reads depend on SymbolSource and writes on SymbolSink; document state has a
   single write path (Section 4.3).
4. No knowledge query takes a cursor position or a caller-supplied syntax tree
   (Section 4.4).
5. No consumer branches on a *resolved* symbol's kind, or on a concrete type
   implementation, to decide suitability; syntactic-position routing is exempt
   (Section 4.5).
6. Types are constructed only via the factory and consumed only via the interface;
   traversal occurs in one place (Section 4.6).
7. Built-in knowledge is parameterized by target environment, not the server's
   runtime (Section 4.7).
8. No output-shaping decision reads raw `initialize` parameters, and no per-client
   quirk conditional leaks into resolution or knowledge code (Sections 4.8, 4.10).
9. Position handling uses one negotiated internal representation and assumes no
   byte offsets in the interior (Section 4.9).
10. Backend precedence holds, caching goes through the replaceable cache seam, and
    bounded coverage is observable (Section 5.3).
11. Correctness holds under synchronous execution (Section 6).
12. Malformed input yields an error response, not process termination (Section 9).

### 8.1. Enforcement

Each invariant MUST have a designated enforcement mechanism, and MUST NOT rely on
human review where a static rule or an automated test is feasible. This is stronger
than "enforce where convenient": the handler x node-type axis stayed closed because
it was held by *mechanism* — a single interface plus a parity test — not by a
documented rule, and every invariant here is expected to be held the same way. An
invariant added by amendment MUST specify its mechanism.

    Invariant                         Mechanism
    --------------------------------  ------------------------------------------------
    4.1 Handler responsibility        Architecture test: handler code MUST NOT depend
                                      on parser, repository, or reflection.
    4.2 SymbolSource authority        Static rule: no ReflectionClass, concrete index,
                                      or autoload-map use outside a backend.
    4.3 Read/write segregation        Static rule: consumers depend on SymbolSource or
                                      SymbolSink, not a concrete impl; single write
                                      path checked by architecture test.
    4.4 Positional/knowledge split    Interface shape: knowledge signatures accept no
                                      position or syntax tree (checked by the type
                                      checker on the interface).
    4.5 Predicates over kind/type     Static rule: no `instanceof` against a concrete
                                      Type impl, and no `match`/`switch` on the kind
                                      enum, outside the factory and classifier.
    4.6 Type factory + traversal      Static rule: no `new` of a Type impl outside the
                                      factory; TypeGraphParityTest for the walk.
    4.7 Env-parameterized built-ins   Test: built-ins resolve against a supplied
                                      environment, and re-key on its change; review.
    4.8 Capability negotiation        Static rule: raw `initialize` params reachable
                                      only within the negotiation component.
    4.9 Position encoding             Test: multibyte round-trip at the boundary;
                                      review of interior offset use.
    4.10 Client conformance defects   Review only; defect list in Appendix B.
    5.2 / 5.3 Write path, precedence, Architecture test + review: one write path,
        cache seam, bounded coverage  caching behind the abstraction, bounds observable.
    6 Synchronous correctness         By construction: the suite runs the interior
                                      synchronously.
    9 Robustness                      Test: malformed frames yield error responses,
                                      not process termination.

Where a listed mechanism does not yet exist, its absence is a known gap to be
closed, not a licence to enforce by review; the gap SHOULD be tracked as an issue.

## 9. Robustness Considerations

Per [LSP], "Base Protocol", the server MUST frame messages by `Content-Length`
and MUST use JSON-RPC 2.0 error semantics. Malformed input MUST NOT terminate the
process: an unparseable message MUST yield a `ParseError` response, and a handler
failure MUST yield an `InternalError` response, rather than crashing the read
loop. A message lacking a required header MUST be distinguishable from end of
stream.

These are robustness requirements, not merely conformance niceties: an editor
session that dies on one malformed frame loses all unsaved server state.

## 10. Rationale (Non-Normative)

Two observations motivate the whole document.

First, the "works on X but not Y" defect is not a series of unrelated bugs; it is
the signature of an axis of variation with more than one uncontrolled
implementation. The handler x node-type axis produced it once and was closed by a
single resolution interface. The data-source axis produces it today: classes,
functions, and constants have three unrelated levels of support because each grew
its own path. The invariants in Sections 4.2–4.7 close the remaining axes by the
same means that worked before — a single authority per axis, reached through
predicates and interfaces rather than concrete-kind inspection.

Second, capability negotiation (Section 4.8) is not one feature among many; it is
a modifier on every message the server emits. If it is decided at each call site,
it becomes an M×N generator spanning the entire outgoing surface — a worse
version of the same defect, on the output-shaping axis. Modeling it as a single
negotiated value whose default *is* the minimal client turns "support for older
clients" from scattered conditionals into the identity case, and confines
encoding — the highest-blast-radius correctness concern, since a wrong offset
edits the user's file incorrectly — to one boundary.

The concurrency position (Section 6) follows from a distinction rather than a
preference: *Fibers* buy responsiveness and enable server-initiated work but no
raw throughput, because they add no parallelism. The language does offer real
parallelism (separate processes/threads) and native acceleration (FFI or an
extension), which are legitimate for background work such as indexing or a parsing
hot path. What the interactive hot path needs first, though, is caching — and that
is independent of, and MUST NOT be deferred for, any concurrency or parallelism
work.

## Appendix A. Axes of Variation Catalog

    Axis                 Extension point                     Governing section
    -------------------  ----------------------------------  -----------------
    Data source          SymbolSource backend                4.2, 5.3
    Symbol kind          kind + lookup + extraction          4.5, 5.1
    Type form            Type implementation + factory        4.6
    Target environment   environment parameter + backend      4.7
    Protocol capability  session capabilities + handler       4.8, 5.4
    Position/intent      completion source + intent mapping   7

Configuration/settings is intentionally not a separate axis (Section 3.1); it folds
into target environment (4.7) and session capabilities (4.8, 5.4).

## Appendix B. Relationship to Existing Invariants and Issues

- The handler x node-type invariant (issues #190, #253, #256) is retained as
  Section 4.1. The single-traversal invariant (issue #334) is retained as the
  second paragraph of Section 4.6. Both predate this document and are unchanged.
- The design issues for workspace queries (#264), batch operations (#265), and
  diagnostics (#266) each require a tier this document constrains but does not
  build: reverse indexing hangs off the SymbolSource authority (Section 4.2);
  batch traversal is a distinct entry that reuses per-node resolution; diagnostics
  are server-initiated output subject to Sections 4.8 and 6. This document defines
  the invariants those tiers MUST satisfy; it does not schedule them.
- **Current divergences (informative).** This RFC is the target state, not a
  description of the code as it stands. Known gaps at adoption time include:
  function resolution requires a caller-supplied syntax tree, uses bare-string
  names, and returns nullable results (violates Sections 4.4 and 5.1); global
  constants do not resolve at all; client capabilities are not read; and position
  offsets are handled as bytes (violates Section 4.9). These are to be migrated
  toward this document, not grandfathered; each SHOULD be tracked as an issue
  citing the section it violates.
- **Observed client defects (informative).** Per Section 4.10, accommodations for
  clients that advertise a capability but mishandle it are recorded here as they
  are found. Known: some clients ignore a completion item's `textEdit` range and
  insert the raw `newText` (the ale plugin for Vim, ale#4274).

## Appendix C. LSP Feature and Version Reference

This document targets [LSP] 3.17. The parts and method names below are stable
across minor revisions; exact subsection titles are not, so citations elsewhere in
this document reference the part and method rather than a subsection heading. These
locations were checked against the live 3.17 specification.

    Invariant / concern         [LSP] 3.17 location
    --------------------------  --------------------------------------------------
    Message framing             Base Protocol → Header Part
    Errors, JSON-RPC            Base Protocol (error codes)
    Cancellation                Base Protocol → Cancellation Support (`$/cancelRequest`)
    Lifecycle and state         Server lifecycle (`initialize`, `shutdown`, `exit`)
    Client/server capabilities  Server lifecycle → `initialize`
                                (ClientCapabilities, ServerCapabilities)
    Position + encoding         Basic JSON Structures → `Position`;
                                `general.positionEncodings` and ServerCapabilities
                                `positionEncoding`
    Document synchronization    Text Document Synchronization (`textDocument/didOpen`,
                                `didChange`, `didClose`)
    Hover markup                Language Features → Hover (`textDocument/hover`);
                                Basic JSON Structures → `MarkupContent`
    Completion, snippets        Language Features → Completion
                                (`textDocument/completion`, `insertTextFormat`)
    Signature help              Language Features → Signature Help
                                (`textDocument/signatureHelp`)
    Diagnostics (push)          Language Features → Publish Diagnostics
                                (`textDocument/publishDiagnostics`)

When the targeted [LSP] version is raised, this appendix and Section 1.4 MUST be
updated, and any newly available negotiation MUST be reconciled with Section 4.8.
