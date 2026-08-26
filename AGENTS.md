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