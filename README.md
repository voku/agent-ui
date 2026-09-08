# agent-ui

[![Build Status](https://github.com/voku/agent-ui/actions/workflows/ci.yml/badge.svg)](https://github.com/voku/agent-ui/actions)
[![Latest Stable Version](https://poser.pugx.org/voku/agent-ui/v/stable)](https://packagist.org/packages/voku/agent-ui)
[![Total Downloads](https://poser.pugx.org/voku/agent-ui/downloads)](https://packagist.org/packages/voku/agent-ui)
[![Monthly Downloads](https://poser.pugx.org/voku/agent-ui/d/monthly)](https://packagist.org/packages/voku/agent-ui)
[![License](https://poser.pugx.org/voku/agent-ui/license)](https://packagist.org/packages/voku/agent-ui)
[![PHP Version Require](https://poser.pugx.org/voku/agent-ui/require/php)](https://packagist.org/packages/voku/agent-ui)
[![GitHub Stars](https://img.shields.io/github/stars/voku/agent-ui?style=flat-square)](https://github.com/voku/agent-ui/stargazers)
[![GitHub Forks](https://img.shields.io/github/forks/voku/agent-ui?style=flat-square)](https://github.com/voku/agent-ui/network/members)

Local, server-rendered human control plane for the governed [`voku/agent-loop`](https://github.com/voku/agent-loop) workflow.

```text
agent-loop        = what should happen
agent-loop-runner = optionally makes an agent do it
agent-ui          = lets a developer understand and control it
```

`agent-ui` presents owner state and invokes owner capabilities. It owns **no** workflow, setup, board, context selection, approval, execution, verification, review, or Learning truth.

## Status

The v0.1 → v0.10 control-plane roadmap is implemented:

- read-only workflow/board/task views;
- typed human decisions and guided coding-agent handoff;
- optional typed Runner controls, with Runner observation kept separate from workflow authority;
- readable evidence, review and audit history;
- repository Setup backed by `agent-loop`'s typed setup service, including stale-plan-bound install/update/remove plus policy/Git sync;
- persisted Recall context explainability with selected/excluded Guidance, hard constraints, omissions and integrity state;
- approved-work/scope-drift/review transparency from the typed workflow projection;
- bounded durable Learning/Knowledge browsing through `agent-learning`;
- an attention-first cockpit that composes Setup, Needs you, Current work, Knowledge and the board without inventing a universal priority algorithm.
- a code search and code visualization surface driven by `agent-map`: hybrid chunk search with visible channel provenance, hash-verified source windows, and a bounded reverse-dependency impact projection.

## Installation

```bash
composer require --dev voku/agent-ui
```

The package exposes the standalone local control-plane server CLI:

```bash
vendor/bin/agent-ui
```

## Quick Start

Start the local control plane in your project root:

```bash
vendor/bin/agent-ui
```

Then open `http://127.0.0.1:8088` in your browser. Bind to loopback; this is a local developer control plane.

### CLI Options

```bash
# Custom port
vendor/bin/agent-ui --port=8080

# Explicit project root
vendor/bin/agent-ui --root=/path/to/project

# Custom host binding
vendor/bin/agent-ui --host=127.0.0.1 --port=9000
```

### Development Run

```bash
composer install
AGENT_UI_PROJECT_ROOT=/path/to/project php -S 127.0.0.1:8088 -t public
```

Top-level navigation is `Overview | Setup | Prompts | Board | Knowledge`. Task routes are `/task/{id}`, `/task/{id}/context`, `/task/{id}/work`, `/task/{id}/evidence`, `/task/{id}/history`, `/task/{id}/prompts`, and `/task/{id}/learning`; `/task/{id}/handoff` redirects to the task's Prompt Workbench. Every task view carries the same navigation across all seven. Human, Runner, and Setup state changes are POST-only and CSRF-protected.

## Setup

`/setup` consumes `voku\AgentLoop\Init\RepositorySetupService` through a thin typed gateway. It does not shell out to `agent-loop init`, capture stdout, scan owner directories, or parse sync manifests.

The page shows each supported host's runtime/integration projection, canonical repository-owned next action, and only the operations the owner currently reports as legal. Install/update/remove plans carry the owner's stable plan identity and expected-state token back through the POST. The gateway re-plans immediately before mutation and the owner revalidates again before writing, so stale browser state cannot become setup authority.

Removal remains deliberately narrow: package-managed unchanged assets may be removed; locally modified/unverifiable managed assets remain blocked; project-owned/unmanaged files and host/user settings such as trust or Auto Mode stay outside this UI's authority.

## Context, work and knowledge

`/task/{id}/context` reads only the persisted Recall explanation. Viewing it never recompiles Recall. Hard constraints, selected Guidance, deterministic exclusions, skipped inputs, budget omissions and integrity failures remain distinct owner facts.

`/task/{id}/work` renders the approved Contract boundary separately from Git repository observation, implementation snapshot and exact review evidence. Scope drift is visible but never mutates the Contract or advances lifecycle state.

The Work ↔ Architecture bridge carries those existing owner facts into Map navigation without merging them: only an approved Contract scope is linked as Loop authority, Git-observed changed paths remain separately attributed, and either path can open Map-owned search/graph evidence. Candidate Contract scope stays visible as candidate state but never becomes "approved scope" navigation before Loop records approval. Task pages also link directly to code search, the architecture graph, and the existing history view used as the current development-trace entry point.

`/knowledge` and `/task/{id}/learning` use `agent-learning`'s bounded typed catalog. Findings, proposals, durable guidance/constraints, rejected/superseded history and owner-recorded usefulness signals remain Learning truth rather than workflow authority.

## Execution modes

The task page makes the executor distinction explicit:

- **Guided coding-agent session**: copy a governed prompt and perform host-native work manually;
- **Managed runner**: when `voku/agent-loop-runner` is installed, render its typed authority/observation status and invoke only controls that Runner currently projects as legal.

Runner remains optional at runtime and a development dependency here only so tests/PHPStan can prove the integration. Because Runner is still pre-release, this root project declares its GitHub VCS repository explicitly. `agent-ui` never parses Runner CLI JSON.

`run` and `resume` are synchronous. The one-process PHP development server therefore cannot service a Cancel request while its own Run/Resume request is blocked. Cancel remains useful when another worker/session observes the owned process; the UI states this limitation instead of pretending it has background execution.

## Evidence and history

`/task/{id}/evidence` adapts `agent-loop`'s public `WorkflowReportCommand::buildReport()` plus typed review-acknowledgement and Learning-decision records into immutable UI snapshots. Raw lifecycle references remain available as a disclosure, but the primary view presents Contract, validation, verification, Recall, review and Learning facts directly.

`/task/{id}/history` sorts only timestamped owner facts: Contract approval, validation executions, exact review acknowledgement, and Learning decisions. It does not infer missing events or treat generated evidence as authority.

## Code search and code visualization

The Map surface answers three questions a developer actually asks about a repository, and `agent-map`
answers all three — the UI adds routing, bounds and rendering, nothing semantic.

**Where is this?** `/map` runs `agent-map`'s `HybridSearch` over its derived chunk index, so a query
reaches method bodies, comments and error strings rather than only symbol names. A hybrid answer
carries the channel mode agent-map reports (`structural+lexical+semantic` when the stored semantic
provider is compatible, otherwise `structural+lexical` with `semantic_channel_unavailable`), the
structural terms it recognised, both snapshots it answered from, and the per-channel ranks each hit
earned — an opaque ranking is the first thing people stop trusting.

When the derived index has not been built, the page falls back to `AgentMapIndex::query()`, which needs
no cache, so a miss from an unindexed repository never reads like "no such code". That fallback is
labelled as the consumer's own: the page says it was composed by agent-ui, marks the mode and reason as
agent-ui labels, and reports that there are no channel ranks, no structural terms and no search-index
snapshot rather than inventing plausible ones. Only the map snapshot is carried through, because only
it is genuinely owner-derived. A result set that quotes the consumer as if it were the owner is worse
than no provenance at all.

```bash
vendor/bin/agent-map build --root=. --paths=src,tests
vendor/bin/agent-map search-index build --root=.
```

**What does it look like?** `/map/source` and the previews under each hit render real repository source
through `agent-map`'s own `SourceMaterializer`. The window is bounded, and the file hash recorded in the
map is checked before a single line is rendered, so the code you read is always the code the callers,
the impact view and the edit context are describing. When materialization is refused, `agent-map`'s own
stale evidence decides what to call it: a file it names stale renders as stale with the owner's reason
(`hash` or `missing`), and a refusal it does not name — a path that really escapes the repository root,
an unreadable file — stays `unavailable` with the owner's message. An unexpected failure stays visible
as one instead of being folded into a diagnosis the map never made.

**What breaks if I change it?** `/map/impact` projects `agent-map`'s reverse-dependency traversal as
concentric rings around the target: distance is traversal depth, dashed edges are uncertain paths, and
every drawn node is repeated below as text with its relation kinds and evidence counts. The picture is a
shortcut to the evidence, never a replacement for it. Depth, node bounds, truncation and uncertainty are
agent-map's answers, preserved rather than rounded off — a dynamic call that *might* reach the target
stays marked uncertain.

The semantic channel is restored only through `agent-map`'s `SearchIndexStore::semanticProvider()`
owner API. That API returns the provider matching the vectors already stored in the derived index, or
`null` when sqlite-vec, persisted state, vectors, or the recorded fingerprint cannot support a truthful
semantic query. The UI passes that result into `HybridSearch`; it never reads embedding metadata,
refits a provider, or silently substitutes another vector space.

## Interface

The interface is one hand-written stylesheet (`templates/layout/app.css`), inlined by the layout.
No framework, no build step, no asset pipeline — the same constraint the rest of the project works
under, and one fewer thing to keep alive for a tool you run locally.

Two ideas drive the visual design, because both are product decisions rather than decoration:

- **Provenance is visible.** Owner authority and Runner observation sit in separate, differently
  marked columns, so a green process exit can never be misread as a passed gate.
- **State carries colour, and unknown states do not.** `src/View/Presentation.php` is the only place
  that interprets an owner state string, and it interprets it for colour and wording only. A state
  agent-loop adds tomorrow renders as neutral rather than being guessed at.

The task page opens with an owner-reference ribbon: every artifact agent-loop projects, with the
state its owning package reports for it. It reads as a lifecycle at a glance, and none of it is
derived here.

Light and dark themes follow the operating system. Commands and prompts carry copy buttons, which
are the only JavaScript in the project and which every page works completely without. That script is
inlined and named in the Content-Security-Policy by hash, so `src/View/ClientScript.php` is the single
place both the shipped script and the policy that admits it come from; the buttons stay hidden until
it runs, rather than rendering as controls that do nothing.

The board is read through `agent-loop`'s `ProjectLayout::boardRoot()` rather than a path spelled here,
so a repository scaffolded by `agent-loop init scaffold` — which keeps its board below the state root —
is read correctly.

## Architecture

```text
browser
  -> tiny HTTP adapter
  -> vertical feature action
  -> typed owner adapter
  -> immutable UI read model
  -> server-rendered HTML
```

The lifecycle rule is intentionally severe: **the UI never derives what happens next or which human/Runner/Setup control is legal.** `voku/agent-loop` projects workflow/setup state and human decisions; `voku/agent-loop-runner` projects managed-execution controls and keeps process state observational; Recall and Learning retain their own truth.

Board config/card parsing similarly comes from `voku/agent-kanban`. There is no duplicated card parser, inferred lane policy, database, ORM, JavaScript framework, or frontend build pipeline.

## Verification

```bash
composer ci
```

Runs Composer validation, PHPUnit, PHPStan at max level, template syntax linting, and php-cs-fixer in check mode on PHP 8.3, 8.4 and 8.5 in CI.

The v0.6 and v0.10 merge gates were each proven on PHP 8.3/8.4/8.5 after their owner prerequisites landed. Owner-package tests provide the destructive setup safety proof against real temporary repositories; agent-ui separately proves it does not bypass those owners or turn stale browser state into authority.
