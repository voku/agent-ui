# agent-ui

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

## Development install

```bash
composer install
```

## Run

```bash
AGENT_UI_PROJECT_ROOT=/path/to/a/project/using-agent-loop php -S 127.0.0.1:8088 -t public
```

Then open `http://127.0.0.1:8088`. Bind to loopback; this is a local developer control plane.

Top-level navigation is `Overview | Setup | Board | Knowledge`. Task routes include `/task/{id}`, `/task/{id}/context`, `/task/{id}/work`, `/task/{id}/evidence`, `/task/{id}/history`, `/task/{id}/learning`, and `/task/{id}/handoff`. Human, Runner, and Setup state changes are POST-only and CSRF-protected.

## Setup

`/setup` consumes `voku\AgentLoop\Init\RepositorySetupService` through a thin typed gateway. It does not shell out to `agent-loop init`, capture stdout, scan owner directories, or parse sync manifests.

The page shows each supported host's runtime/integration projection, canonical repository-owned next action, and only the operations the owner currently reports as legal. Install/update/remove plans carry the owner's stable plan identity and expected-state token back through the POST. The gateway re-plans immediately before mutation and the owner revalidates again before writing, so stale browser state cannot become setup authority.

Removal remains deliberately narrow: package-managed unchanged assets may be removed; locally modified/unverifiable managed assets remain blocked; project-owned/unmanaged files and host/user settings such as trust or Auto Mode stay outside this UI's authority.

## Context, work and knowledge

`/task/{id}/context` reads only the persisted Recall explanation. Viewing it never recompiles Recall. Hard constraints, selected Guidance, deterministic exclusions, skipped inputs, budget omissions and integrity failures remain distinct owner facts.

`/task/{id}/work` renders the approved Contract boundary separately from Git repository observation, implementation snapshot and exact review evidence. Scope drift is visible but never mutates the Contract or advances lifecycle state.

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
are the only JavaScript in the project and which every page works completely without.

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
