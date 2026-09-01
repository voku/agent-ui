# Workflow feedback from the 2026-08 UI/UX blind-spot dogfood

This file records friction found in the **workflow tooling** while dogfooding it, not in
`agent-ui`. Nothing here is an agent-ui defect, and nothing here has been acted on: each item
belongs to an owning package and needs its own governed change there.

Board cards `UI-15`–`UI-20` (`domain=workflow`) track the same items.

| Card | Owner | Item |
| --- | --- | --- |
| UI-15 | agent-loop / agent-kanban | Nothing steers a board consumer to the loop-owned board root |
| UI-16 | agent-recall-compiler | `review blindspots` checks artifact presence, not semantic blind spots |
| UI-17 | agent-loop | Board lane and workflow state can disagree silently |
| UI-18 | agent-loop | `install-assets` cannot tell a live host session to reload |
| UI-19 | this repository | `composer install` cannot complete behind a restricted egress proxy |
| UI-20 | agent-loop | `finish --format=json` hides the reason a step refused |

## UI-15 — nothing steers a board consumer to the loop-owned board root

`agent-loop init scaffold` writes the board to `.agent-loop/todo/`. `agent-kanban`'s
`BoardContextFactory::create()` defaults to `<root>/todo`. A consumer that passes its project
root therefore gets `ConfigurationException: Could not determine the project prefix` rather
than the repository's real board.

The correct answer, `voku\AgentLoop\ProjectLayout::boardRoot()`, lives in a third package and
nothing in the kanban entry point mentions it. This is exactly the defect finding UI-2 records:
agent-ui had passed its project root and 500'd on every page.

Worth considering upstream: agent-loop exposing a typed board-context accessor, or
`BoardContextFactory` accepting the loop layout, so that no consumer has to know the convention.

## UI-16 — `review blindspots` checks artifact presence, not semantic blind spots

Run against a task with no Contract, `agent-loop review blindspots UI-1` reports five findings:
missing `meta.json`, missing `validation-plan.md`, no validation-evidence marker, no outcome
close-out marker, no review checkpoint marker. All five are file-presence checks.

That is a useful gate, but the command name reads as a semantic review helper. A first-time
dogfooder asked to "run a blind spot analysis" reaches for it and gets a file checklist. The
command help could say which of the two it is, or artifact-presence verification could be split
from the L2 review prompt the same command emits.

## UI-17 — board lane and workflow state can disagree silently

`board card create UI-1 --lane=DOING --status=in_progress` succeeds on its own. The workflow
then projects `state=incomplete`, `mode=legacy_inferred`, because no Contract exists — and
`agent-loop verify` reports **no drift**. The board says work is in progress while the workflow
says nothing has been planned, and no tool says so.

Worth deciding upstream: whether `verify` should surface a lane/lifecycle disagreement, and if
so which package owns that check. agent-ui deliberately must not derive it.

## UI-18 — `install-assets` cannot tell a live host session to reload

`init install-assets --agent=claude` prints:

> [INFO] Start a fresh Claude Code session so the project agent registry is re-read.

Nothing afterwards reports whether the running session actually consumed the projection, so an
agent can believe it has skills it cannot read. A "projected but not loaded in this session"
boundary fact in `init host-status` would make that observable rather than advisory.

## UI-19 — `composer install` cannot complete behind a restricted egress proxy

`phpstan/phpstan` is dist-only in `composer.lock`: its lock entry carries no `source`, and its
`dist.url` is an `api.github.com` zipball. A policy-restricted session cannot fetch it, and
`--prefer-source` cannot help because there is no source entry to fall back to. Bootstrapping
this repository's own gates therefore needs a manual workaround (fetch the pinned commit with
git and place it under `vendor/phpstan/phpstan`).

Everything else in the lock installed cleanly from source. Worth deciding: whether the lock
should carry a source entry for phpstan, or whether the bootstrap documentation should name the
workaround.

## UI-20 — `finish --format=json` hides the reason a step refused

`agent-loop finish UI-1 --learning follow_up_required --learning-reason "…" --by … --format=json`
returned the same `next_action` template it had returned before the call, with nothing to indicate
that anything had failed. The same command without `--format=json` printed:

> [FAIL] finish: follow_up_required requires a follow-up reference.

A host obeying the JSON contract sees an unchanged next step and cannot distinguish a refusal from
a no-op; the natural response is to re-issue the identical command. (A second, smaller point: the
`finish` next-action template advertises `[--finding <finding-id> ...]` but not `--follow-up-ref`,
which is what `follow_up_required` actually needs. `finish --help` does list it.)

Worth considering upstream: carrying the refusal reason into the JSON result.
