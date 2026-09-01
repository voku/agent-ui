# AGENTS.md

## Repository role

`voku/agent-ui` is the local, server-rendered human control plane for the governed `voku/agent-loop` workflow. It presents owner state and invokes owner capabilities through typed gateways.

The UI owns presentation, HTTP normalization, CSRF-protected interaction, immutable UI read models, and deterministic composition of already-owned projections. It owns no workflow, board, Recall, execution, verification, review, setup, or durable Learning truth.

## Dependency direction

The UI is a consumer of semantic owners:

- `voku/agent-loop` owns lifecycle/setup authority, governed next actions, Contract/Run state, human decisions, and workflow projections.
- `voku/agent-kanban` owns board/card semantics.
- `voku/agent-recall-compiler` owns Recall context and operating-prompt recipe semantics.
- `voku/agent-learning` owns durable Learning/Knowledge semantics.
- `voku/agent-loop-runner` is optional and owns managed-execution controls plus Runner-private observations; Runner observations remain separate from Loop authority.

Do not parse owner CLI output, private JSON files, card Markdown, or internal filesystem layouts in order to implement a UI feature. If the necessary fact/control is missing, add the smallest typed API to the semantic owner first, release it, then consume the stable owner contract here.

## Invariants to preserve

- The UI never derives what happens next or which lifecycle/human/Runner/Setup action is legal. Render the owner's projected action/capability.
- Unknown/new owner states render neutrally; presentation must not guess semantics from strings.
- Provenance stays visible. Do not merge owner authority and Runner/process observation into one success state.
- State-changing requests are POST-only and CSRF-protected. Re-plan/revalidate through the owner before mutation when stale browser state could matter.
- Server-rendered HTML is the default. Keep the no-framework/no-build-step constraint unless a concrete requirement proves it insufficient.
- JavaScript is optional enhancement only; core pages/actions must remain useful without it.
- The UI must not create a second database, workflow state store, recipe catalog, board parser, or Learning policy engine.

## Prompt Workbench boundary

For deterministic prompt tooling:

- Recall owns recipe catalog, recipe metadata, typed arguments, rendering/template identity, and recipe applicability metadata.
- Loop owns workflow/lifecycle authority and workflow prompt envelopes.
- UI may explicitly select a Recall recipe, collect only owner-declared arguments, compose owner projections deterministically, show provenance/digests, and provide Copy.
- UI must not hard-code per-recipe lifecycle rules, duplicate the recipe catalog, infer approval from board state, or make a blocked workflow prompt copyable.
- Free-form developer instruction fields are allowed only when the Recall owner contract explicitly permits them.
- Equivalent owner inputs must produce equivalent prompt bytes/digests; do not put timestamps or volatile presentation data into deterministic prompt identity.

## Dependency/release discipline

Production integrations should consume stable released owner APIs. For a cross-package feature, prefer this order:

1. add/fix the typed semantic owner contract;
2. merge and release the owner with its marker-driven release flow;
3. update this package's stable constraint;
4. run this package's full integration gates;
5. merge the UI change.

A temporary candidate/dev dependency may be useful for proving compatibility, but it is not final release evidence. Runner may remain an explicit optional development dependency while it is unreleased; do not let that leak into required runtime authority.

## Validation

Run:

```bash
composer ci
```

This includes strict Composer validation, PHPUnit, PHPStan, template syntax linting, and php-cs-fixer check mode.

## Releases

Releases are marker-driven. A `.release/<version>.json` marker must point at a release-ready ancestor commit whose own `CHANGELOG.md` contains the release section. Existing tags are immutable.

<!-- agent-loop:project-instructions:begin -->
## agent-loop workflow router

This repository uses `voku/agent-loop` for governed coding work. Keep this always-on router small; detailed procedures live in the installed skills and CLI help. If host integration is uncertain, run `vendor/bin/agent-loop init host-status --format=json` and obey its `next_action_kind` / `next_action` until no repository-owned action remains; `runtime_boundary` describes host/user scope and is not authority to mutate it. Troubleshooting only: use `vendor/bin/agent-loop init status` to inspect broader setup and `init sync-instructions` when managed instruction projection itself needs repair.

Before the lifecycle CLI is runnable, recover only the minimum reversible workspace/tool bootstrap needed to execute the repository's declared workflow: inspect the checkout/remotes, fetch the obvious public repository, install already-declared Composer dependencies, obtain required public sibling checkouts for cross-package work, discover available host/GitHub capabilities without exposing credentials, and establish an isolated branch or worktree before implementation. This bootstrap is not product-code mutation and does not authorize approval, task state, or owner artifacts. Missing `vendor/`, a missing remote, or an unavailable preferred PR/push tool is not by itself a terminal workflow blocker; continue safe local work until the next genuinely required action cannot be performed.

For a durable task id:

1. Before mutating product code, run `vendor/bin/agent-loop enter <task-id> --format=json` and obey the returned `next_action_kind` / `next_action`. `command` means execute it as written; `command_template` means fill model-owned placeholders from the actual request and repository evidence and execute it without asking a human merely because placeholders exist; `decision_required` means a genuine human-authority decision is required, so show the exact current decision subject before asking; `host_work` means perform the described host-native implementation/model work; `none` means there is no further lifecycle action. Never fabricate an approval or risk owner.
2. Use repository-managed skills and subagents when their descriptions match the task. Do not recreate their procedures from conversational memory. In particular, do not pre-build Map/Search, create Session/Recall state, or infer approval/close ordering: deterministic prerequisites and repairs must come from the canonical lifecycle result.
3. When host-native mutation is complete, run `vendor/bin/agent-loop finish <task-id> --format=json`, then obey its canonical next step until `next_action_kind=none` and the result is complete. If a human decision is requested, present the exact Contract/review/Learning/risk evidence being decided instead of asking for a generic confirmation. If an advertised command deterministically refuses without changing the next step, report a workflow defect rather than teaching the host a private workaround.
4. Never claim that hooks fired, checks passed, CI is green, a PR merged, or a release/deploy shipped unless current evidence proves it.

For untracked exploration, use an ephemeral session rather than inventing a durable task.
<!-- agent-loop:project-instructions:end -->
