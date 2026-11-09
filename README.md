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

## Where to work in this repository

`agent-ui` presents owner truth; it must not reimplement it. These paths identify where a UI change belongs:

| Concern | Source of truth |
| --- | --- |
| HTTP input, routing and security | [`src/Http/`](src/Http/) and [`src/Security/`](src/Security/) |
| Owner-backed integration/adapters | [`src/Integration/`](src/Integration/) |
| Task views and application models | [`src/Feature/`](src/Feature/), [`src/Application/`](src/Application/) and [`src/View/`](src/View/) |
| Server-rendered pages and styling | [`templates/`](templates/) and [`templates/layout/app.css`](templates/layout/app.css) |
| Local entry point and browser assets | [`public/`](public/), [`bin/`](bin/) and [`src/View/client.js`](src/View/client.js) (loaded by [`ClientScript.php`](src/View/ClientScript.php)) |
| Experimental local terminal transport | [`tools/terminal/local-cockpit.cjs`](tools/terminal/local-cockpit.cjs) (session start/stop, not workflow authority) |
| Regression and security contracts | [`tests/`](tests/) and [`composer.json`](composer.json) |

A browser control never grants governed workflow authority by itself. Owner-state mutations use the appropriate typed owner API and CSRF-protected POST routes; the experimental local terminal has a separate authenticated session-transport boundary. Runner observations remain separate from Loop decisions.

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

The Host/Origin guard uses PHP's server-provided request scheme. If TLS terminates before PHP, configure the trusted web server to set `HTTPS=on` for secure requests; agent-ui does not infer the scheme from `X-Forwarded-Proto`.

`/search` is a read-only global search over what the owners publish (tasks, Findings, Proposals and Guidance, symbols and code, prompt recipes), reachable from a form in every page's header; `/` focuses it when scripts are on. It keeps each owner's results apart, in the owner's order, with no score, and reports an owner it could not ask as unavailable rather than as "no matches". The primary navigation groups existing destinations by developer intent: **Work** (Overview and Tasks), **Knowledge**, **Code** (Map), and **Tools** (Prompts, Commands, and Setup). The current page is marked in the navigation, and all links work without JavaScript. Task views are `/task/{id}`, `/task/{id}/progress`, `/task/{id}/contract`, `/task/{id}/context`, `/task/{id}/work`, `/task/{id}/evidence`, `/task/{id}/history`, `/task/{id}/prompts`, `/task/{id}/learning`, and `/task/{id}/edit`; `/task/{id}/handoff` redirects to the task's Prompt Workbench. Every task view carries the same grouped navigation. Human, Runner, and Setup state changes are POST-only and CSRF-protected.

The workflow progress view refreshes its Loop-owned projection while the page is open through the read-only, bounded `/task/{id}/progress.json` endpoint. It polls every four seconds while visible, less frequently in a background tab or after completion, and keeps the graph's position and selection. If refresh fails, the last rendered state stays visible with an unavailable notice and retry control. With JavaScript disabled, the server-rendered workflow and decision controls remain available on reload.

### Unified browser workbench (live workflow + CLI + decisions)

Start the cockpit with a task that already exists in the project's agent-kanban board:

```bash
vendor/bin/agent-ui session serve UI-145 --root=.
```

Open the printed local access URL. The task's **Workflow** view combines a
server-rendered developer briefing, live Loop state and graph, the current exact
canonical action, required human decisions, and a persistent terminal.
Choose **Interactive shell (any CLI)** and **Start session**; the shell has
`AGENT_LOOP_TASK_ID` and `AGENT_UI_PROJECT_ROOT` available.

Inside that browser terminal, use the installed coding host you already prefer
(for example Codex, Claude, agy or OpenCode). That host, **not agent-ui**,
continues the existing Loop lifecycle:

```bash
vendor/bin/agent-loop enter UI-145 --format=json
# Follow next_action_kind and the exact owner-projected next_action.
# Use your normal host tools to implement only after the owner permits mutation.
vendor/bin/agent-loop finish UI-145 --format=json
# Repeat until the owner reports completion or a genuine human decision.
```

The browser polls `progress.json`, updating the HTML briefing, visual graph and
decision card without replaying a terminal transcript. The briefing names the
Contract goal, active stage, authorization status, paths in scope, explicit
non-goals and *planned* validation, and links to actual evidence/review.
Those summaries are deliberately **owner projections, not generated claims
about what the agent is thinking**. If an agent runs in PhpStorm or SSH instead,
the same Workflow view still observes its Loop state without launching a shell.

A human can inspect the exact Contract revision, then submit **Approve Contract**
in the browser. The JavaScript enhancement sends the form to the existing
revision-checked, CSRF-protected Loop service and refreshes the owner state
without reloading the terminal; the no-JavaScript form still works normally.
When available, the same workbench presents exact review-report acknowledgement
and supported Learning disposition forms through the existing typed routes.
A failed/stale decision shows an error, never simulated success. Approving a
Contract does **not** approve the subsequent implementation, tests or review.

This is a **local-only experimental** shell with the privileges of your OS user.
Do not expose the access URL or bind it to public interfaces. The terminal and
its optional image upload require independent security review and authenticated
host end-to-end acceptance before production deployment.

### Experimental local terminal cockpit

The optional terminal prototype puts a persistent, generic interactive shell beside the workflow progress view. It is intended for a single user on a local POSIX machine, not remote or shared deployments. It requires Node.js 22+, `tmux`, `ttyd` 1.7.4+ and PHP. The shell is `/bin/sh`; coding agents are optional programs installed separately. The regular UI and external terminal workflows do not require any of these terminal tools.

```bash
# Open the task cockpit without starting a coding host.
vendor/bin/agent-ui session serve UI-145 --root=.

# Start a normal shell; run any installed terminal CLI inside it.
vendor/bin/agent-ui session start UI-145 --root=.
# Within the shell: agy, codex, claude, composer ci, git status, etc.

# Optional direct host shortcut (agy, codex, claude).
vendor/bin/agent-ui session start UI-145 --host=agy --root=.

# Reopen, inspect, or explicitly stop the same task session.
vendor/bin/agent-ui session attach UI-145 --root=.
vendor/bin/agent-ui session status UI-145 --root=. --format=json
vendor/bin/agent-ui session stop UI-145 --root=.
```

Open the access URL printed by the command on the same machine. Treat the URL as a password: its fragment grants access to a writable terminal with the coding host's OS permissions. The transport binds to `127.0.0.1`, requires a session-specific browser credential, checks Host and Origin, isolates the terminal frontend on a separate loopback origin, and uses a private Unix socket for `ttyd`. The cockpit also launches a second, private PHP development server on an ephemeral loopback port; every request to that backend requires a per-launch secret known only to the Node gateway and child PHP process. Requests directly to its port without the secret are rejected before PHP opens a session or reads task state, and browser-provided copies of the internal header are overwritten by the gateway. Ordinary standalone `agent-ui` launches remain unchanged. This controls direct HTTP bypass but is **not** a sandbox against other software running with the same OS-user privileges. It permits one browser terminal viewer per task. It intentionally exposes a full OS-user shell, but no unauthenticated command API; it never automatically accepts host prompts or Loop decisions. The task's canonical next action remains visible in the UI; follow the normal agent-loop workflow in the coding host.

With `session serve`, choose **Interactive shell (any CLI)** and **Start session** in the page; optional direct `agy`, `codex`, or `claude` shortcuts remain available. Opening the page alone does not start one. The **Disconnect** button closes the browser viewer only; **Stop session** explicitly stops the coding host after confirmation and does not close the governed task. Reload or reconnect to return to the same tmux session. Stopping the local transport also leaves the coding host running. The UI displays the `tmux attach-session` command with a copy button for attaching from any external terminal emulator. The session exports `AGENT_LOOP_TASK_ID` and `AGENT_UI_PROJECT_ROOT` for task-aware commands, but never automatically starts or completes an agent-loop lifecycle. To switch agents, start the generic shell and invoke whichever CLI is installed there; no vendor-specific `agent-ui` integration is required. A missing session is reported and `session attach` never starts a replacement. `session start` and the browser start control explicitly request a new host if none exists. The CLI and browser use the same session operations.

The progress page also offers a **shared coding-agent activity** panel with an explicit **Codex App Server** or **Claude Code stream-json** provider selector. This is observational and separate from the canonical Loop lifecycle. Both provider modes start their **own separate process**, not an attachment to an already-running Codex/Claude TUI. Switching the UI selector does not migrate or merge sessions; only one structured provider session may be active at a time. Generic CLI/`agy` execution continues to work in the normal PTY, but provides no structured activity unless that CLI exposes a supported adapter.

**Claude Code** mode requires a locally installed Claude CLI supporting documented print-mode `--input-format stream-json`, `--output-format stream-json` and `--permission-prompts none` (Claude Code v2.1.259 or later for that last flag), plus local authentication. Prompts are sent as stream-json user messages; only bounded session, tool-name and completion labels are exposed to the browser. Model text, tool inputs and results are not retained. Claude tool approvals are **not** handled in this mode: unanswered permission prompts are denied, and existing allow rules may still automatically permit some operations. We do not bypass host permissions or claim an SDK `canUseTool` bridge. Stop the Claude session to interrupt its work; a CLI that exits unexpectedly is reported as failed.

The **Codex App Server** provider retains its existing approval interface. It requires the Codex CLI and an authenticated Codex account on the local host. It starts a separate App Server thread in the project directory with workspace-write sandboxing and on-request approvals; it does not attach to an existing Codex TUI. Prompts are sent to that thread, while the activity feed keeps only bounded event labels and timestamps. Message deltas, reasoning, and command output are not displayed or retained by agent-ui. Command and file-change approval requests require a deliberate **Allow once**, **Decline**, or offered **Cancel request** action; other request types remain unanswered and the session can be stopped. These events never update Loop workflow state. Reloading the page keeps the local App Server session available while the cockpit process is running; stopping that process ends the App Server child.

### Image input in the local terminal

With a running terminal session, choose an image file, drag it onto the attachment panel, or click **Paste image from clipboard** (browser clipboard permission required). The authenticated browser uploads PNG/JPEG/WebP/GIF only, up to 8 MiB each, into a private task-scoped temporary directory. The server validates the declared MIME type and file signature, uses generated filenames, limits stored images to 32, and types **only the image's absolute path** into the tmux PTY. It does **not** press Enter or execute a command. Whether a coding agent interprets that path as visual input depends on the agent; the developer remains responsible for invoking it correctly.

The files survive stopping the browser transport so a still-running tmux session can use them, and are deleted on explicit task session stop. An uncleanly abandoned session can leave temporary files; review and remove stale `agent-ui-attachments-<uid>-<task-hash>` directories manually. Clipboard reads may be restricted by the browser; the file picker remains available. Direct Ctrl+V **inside the cross-origin terminal iframe** is not yet intercepted; use the clipboard button above it.

This is a prototype: no workflow progress is inferred from terminal output, and a failed terminal transport is reported while workflow navigation remains available. `tmux` may retain the environment of its existing server, so environment changes made after that server started might not reach a new coding host. If the transport is killed uncleanly, its private lock directory can remain under `/tmp`; verify the PID in its `pid` file is no longer a live transport before removing that directory. No terminal transcript is stored in Loop projections.

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

Light and dark themes follow the operating system. JavaScript enhances copy, search, graphs and live
workflow refresh; the server-rendered pages remain usable without it. The script is inlined and named
in the Content-Security-Policy by hash, so `src/View/ClientScript.php` is the single place both the
shipped script and the policy that admits it come from; enhanced controls stay hidden until it runs.

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
