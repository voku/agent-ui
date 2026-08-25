# agent-ui

Local, server-rendered human control plane for the governed [`voku/agent-loop`](https://github.com/voku/agent-loop) workflow.

```text
agent-loop        = what should happen
agent-loop-runner = optionally makes an agent do it
agent-ui          = lets a developer understand and control it
```

`agent-ui` presents owner state and invokes owner capabilities. It owns **no** workflow, board, approval, execution, verification, review, or Learning truth.

## Status

The initial roadmap has reached v0.5: read-only workflow/board views, typed human decisions, guided coding-agent handoff, optional managed Runner controls, and readable evidence/review/Learning/history UX.

The interface was rebuilt on a proper design system in the same milestone — see [Interface](#interface).

## Development install

```bash
composer install
```

## Run

```bash
AGENT_UI_PROJECT_ROOT=/path/to/a/project/using-agent-loop php -S 127.0.0.1:8088 -t public
```

Then open `http://127.0.0.1:8088`. Bind to loopback; this is a local developer control plane.

Routes include `/`, `/board`, `/task/{id}`, `/task/{id}/evidence`, `/task/{id}/history`, and `/task/{id}/handoff`. Human and Runner state changes are POST-only and CSRF-protected.

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

The lifecycle rule is intentionally severe: **the UI never derives what happens next or which human/Runner control is legal.** `voku/agent-loop` projects lifecycle state and human decisions; `voku/agent-loop-runner` projects managed-execution controls and keeps process state observational.

Board config/card parsing similarly comes from `voku/agent-kanban`. There is no duplicated card parser, inferred lane policy, database, ORM, JavaScript framework, or frontend build pipeline.

## Verification

```bash
composer ci
```

Runs Composer validation, PHPUnit, PHPStan at max level, and php-cs-fixer in check mode on PHP 8.3, 8.4 and 8.5 in CI.
