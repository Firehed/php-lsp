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
    5. Component Requirements
       5.1. SymbolSource: Read Contract
       5.2. SymbolSource: Write Contract
       5.3. Backend Substitutability and Caching Policy
       5.4. Session Capabilities
    6. Concurrency Model
    7. Extensibility Procedure
    8. Conformance
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
organized by named sections rather than numbers; citations name the section
(e.g. [LSP], "Text Documents → Position").

## 2. Terminology

- **Symbol**: A named, addressable program entity — a class-like, function,
  constant, or class member.
- **Class-like**: Any of class, interface, trait, or enum, and any future value
  or sum type that participates in member resolution (Section 4.5).
- **Symbol kind**: The coarse category of a symbol used for discovery. Discovery
  distinguishes only the categories that name resolution distinguishes; it does
  not distinguish flavours of class-like.
- **SymbolSource**: The single abstraction through which symbol existence,
  metadata, definition location, and namespace enumeration are answered
  (Section 4.2).
- **Backend**: A concrete provider of symbol knowledge to the SymbolSource
  (e.g. open documents, workspace-on-disk, vendored dependencies, language
  built-ins).
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

SymbolSource read operations and document-lifecycle write operations MUST be
exposed as separate interfaces. A consumer that only reads MUST depend only on
the read interface. A single implementation MAY provide both.

There MUST be exactly one write path for document state. A single document change
MUST NOT update two independent stores.

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

### 4.8. Protocol Capability Negotiation

Client capabilities ([LSP], "Client Capabilities") MUST be read during the
`initialize` request ([LSP], "Lifecycle Messages → Initialize Request") and
resolved once into an immutable session-capabilities value (Section 5.4).

Advertised server capabilities MUST be derived from the intersection of features
the server implements and capabilities the client declares. The server MUST NOT
advertise a capability it cannot honor, nor one the client cannot consume.

Any decision that shapes an outgoing message by client support (for example,
markup kind for hover contents per [LSP] "Hover Request"; snippet support per
[LSP] "Completion Request"; resolve support; diagnostic tags) MUST query the
session-capabilities value. A component MUST NOT re-inspect the raw `initialize`
parameters.

The absence of a client capability MUST resolve to a safe default that is the
value's own default state, not a special-cased branch at the point of use.
Minimal or older clients are therefore served by the default configuration and
require no dedicated code path.

Lifecycle state MUST be enforced per [LSP], "Lifecycle Messages": requests
received before `initialize` MUST be answered with `ServerNotInitialized`, and
requests received after `shutdown` MUST be answered with `InvalidRequest`.

### 4.9. Position Encoding

Position offsets MUST be treated per [LSP], "Text Documents → Position", under
which `character` is measured in UTF-16 code units by default, with `utf-8` and
`utf-32` available only when negotiated via `positionEncoding` ([LSP],
"Capabilities", 3.17).

Encoding conversion MUST occur at the transport/document boundary, into a single
internal representation. Interior components MUST be encoding-agnostic. The server
MUST NOT assume that a `character` offset is a byte offset.

## 5. Component Requirements

### 5.1. SymbolSource: Read Contract

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

### 5.2. SymbolSource: Write Contract

The write interface MUST provide document open, update, and close operations
keyed by document identity. It MUST be the sole means of mutating symbol state
from document changes (Section 4.3). Open-document state MUST take precedence
over on-disk state for the same symbol.

### 5.3. Backend Substitutability and Caching Policy

Backends MUST be substitutable: each MUST satisfy the same contract, and a
backend that cannot answer a query MUST return an empty or null result. A backend
MUST NOT raise an error to signal "not found," and MUST NOT return a partial or
approximate answer presented as complete.

Caching policy is per backend and MUST follow the stability of the source:

- Open documents change on every keystroke and MUST NOT be cached; a stale answer
  here is a renamed symbol still being offered.
- Workspace-on-disk MAY be resolved lazily on demand and MAY be indexed in the
  background; any background indexing MUST be bounded (Section 6) and MUST report
  what it bounded or skipped rather than silently truncating.
- Vendored dependencies and language built-ins are fixed for the life of a
  session and SHOULD be cached for the session.

Where a bound is applied to coverage (a cap, a skipped directory, a
non-retried failure), the server MUST make that omission observable rather than
presenting truncated results as complete.

### 5.4. Session Capabilities

Negotiation (Section 4.8) MUST produce a single immutable value that is
constructed once and injected into components that shape output. It MUST expose
named queries (for example, hover markup kind, snippet support, position
encoding) and MUST yield safe defaults for every capability the client did not
declare.

## 6. Concurrency Model

The architecture neither requires nor precludes an event-loop or Fiber-based
runtime. PHP Fibers provide cooperative concurrency on a single thread, not
parallelism; they do not accelerate CPU-bound work such as parsing or
resolution.

Therefore:

- CPU-bound resolution and knowledge queries MUST remain correct under purely
  synchronous execution. Correctness MUST NOT depend on an event loop.
- Throughput improvements to the hot path MUST come from caching and memoization,
  not from concurrency. Introducing a concurrency runtime MUST NOT be treated as
  a substitute for those.
- If adopted, asynchronous execution MUST be confined to the transport, dispatch,
  and scheduling tier — for request cancellation ([LSP], "Base Protocol →
  Cancellation Support"), non-blocking dispatch, debounced server-initiated
  notifications, and cooperatively-yielding background indexing. It MUST NOT
  require the resolution interior to become asynchronous.
- Any long-running background task MUST yield cooperatively so that interactive
  requests are not starved, and MUST be cancelable.

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

A change conforms to this document if all of the following hold:

1. No handler performs resolution or knowledge lookup (Section 4.1).
2. No symbol existence, location, metadata, or enumeration query bypasses
   SymbolSource (Section 4.2).
3. No consumer branches on a concrete symbol kind or type implementation to
   decide suitability (Section 4.5).
4. Types are constructed only via the factory and consumed only via the interface
   (Section 4.6); traversal occurs in one place.
5. No output-shaping decision reads raw `initialize` parameters (Section 4.8);
   position handling assumes no particular encoding in the interior (Section 4.9).
6. Correctness holds under synchronous execution (Section 6).

Automated enforcement SHOULD be added where feasible (for example, a parity test
that the members reported for a type equal those the language exposes at runtime,
retained from the existing traversal invariant; and static checks that consumers
do not depend on concrete kinds or indices).

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

The concurrency position (Section 6) follows from a fact rather than a
preference: Fibers buy responsiveness and enable server-initiated work, but no
raw throughput, because the language offers no parallelism. The performance wins
this codebase needs are in caching, and those are independent of, and MUST NOT be
deferred for, any concurrency work.

## Appendix A. Axes of Variation Catalog

    Axis                 Extension point                     Governing section
    -------------------  ----------------------------------  -----------------
    Data source          SymbolSource backend                4.2, 5.3
    Symbol kind          kind + lookup + extraction          4.5, 5.1
    Type form            Type implementation + factory        4.6
    Target environment   environment parameter + backend      4.7
    Protocol capability  session capabilities + handler       4.8, 5.4
    Position/intent      completion source + intent mapping   7

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

## Appendix C. LSP Feature and Version Reference

This document targets [LSP] 3.17. Sections most relevant to the invariants:

    Invariant / concern         [LSP] 3.17 section
    --------------------------  ----------------------------------------------
    Message framing, errors     Base Protocol
    Cancellation                Base Protocol → Cancellation Support
    Lifecycle and state         Lifecycle Messages → Initialize / Shutdown / Exit
    Client/server capabilities  Client Capabilities; Server Capabilities
    Position encoding           Text Documents → Position; Capabilities (3.17)
    Document synchronization    Text Document Synchronization
    Hover markup                Hover Request
    Completion, snippets        Completion Request
    Signature help              Signature Help Request
    Diagnostics (push)          Publish Diagnostics Notification

When the targeted [LSP] version is raised, this appendix and Section 1.4 MUST be
updated, and any newly available negotiation MUST be reconciled with Section 4.8.
