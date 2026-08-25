# agent-ui

Local, server-rendered human control plane for the governed [`voku/agent-loop`](https://github.com/voku/agent-loop) workflow.

```text
agent-loop        = what should happen
agent-loop-runner = optionally makes an agent do it
agent-ui          = lets a developer understand and control it
```

`agent-ui` presents owner state and invokes owner capabilities. It owns **no** workflow, board, approval, execution, verification, review, or Learning truth.

## Status

The implementation has reached the v0.3 guided coding-agent handoff slice. The staged roadmap through v0.5 is tracked in [issue #1](https://github.com/voku/agent-ui/issues/1).

## Development install

```bash
composer install
```

## Run

```bash
AGENT_UI_PROJECT_ROOT=/path/to/a/project/using-agent-loop php -S 127.0.0.1:8088 -t public
```

Then open `http://127.0.0.1:8088`. Bind to loopback; this is a local developer control plane.

Routes include `/`, `/board`, `/task/{id}`, `/task/{id}/evidence`, and `/task/{id}/handoff`. State-changing human decisions are POST-only, CSRF-protected, and redirect back to freshly projected owner state.

The guided handoff is deliberately presentation-only: it packages board context with the exact owner-projected `state`, `next_action_kind`, and `next_action` for a coding-agent session. It does not advance lifecycle state, compile new authority, or decide whether the agent may perform a human decision.

## Architecture

```text
browser
  -> tiny HTTP adapter
  -> vertical feature action
  -> typed owner adapter
  -> immutable UI read model
  -> server-rendered HTML
```

The lifecycle rule is intentionally severe: **the UI never derives what happens next or which human decision is legal.** `voku/agent-loop` projects lifecycle state, canonical next action, and currently recordable human decisions; the UI renders those values and delegates writes back to the owner service.

Board config/card parsing similarly comes from `voku/agent-kanban`. There is no duplicated card parser, inferred lane policy, database, ORM, JavaScript framework, or frontend build pipeline.

## Verification

```bash
composer ci
```

Runs Composer validation, PHPUnit, PHPStan at max level, and php-cs-fixer in check mode.
