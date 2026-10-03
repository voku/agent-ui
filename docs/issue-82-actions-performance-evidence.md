# Issue #82 GitHub Actions performance evidence

This document records the independent GitHub Actions proof for issue #82,
`perf: batch workflow projections on multi-card pages`.

The production change itself was delivered by PR #84. The purpose of this
follow-up proof was to verify the released `voku/agent-loop 0.20.52`
`RunManifestProjector::projectMany()` path under hosted CI rather than rely
only on the original local-project timings.

## Final proof setup

The temporary proof branch added one integration test only; no production
files were changed.

The test created:

- 85 real board cards;
- one 32 MiB shared Search artifact at the path returned by
  `ProjectLayout::mapSearchIndex()`;
- 85 individual `WorkflowProjectionGateway::task()` projections;
- one `WorkflowProjectionGateway::tasks()` projection for the same cards;
- exact equality assertions between every individual and batched snapshot;
- one invalid task id to verify that a bad card remains isolated;
- real `GET /` and `GET /board` requests, both required to return HTTP 200.

The large shared artifact intentionally amplifies the repeated owner-read cost.
It is a stress fixture, not a model of every real repository.

## Final Actions evidence

Final proof commit:

`f4b866b660bfd89f95512ac51cfb25b4f88725fa`

CI run:

https://github.com/voku/agent-ui/actions/runs/37131449122

Runner optional-matrix run:

https://github.com/voku/agent-ui/actions/runs/37131449063

| Lane | 85 x task() | tasks() | Observed speed-up | Home | Board |
| --- | ---: | ---: | ---: | ---: | ---: |
| PHP 8.3 | 9148.52 ms | 115.15 ms | 79.45x | 124.24 ms | 122.09 ms |
| PHP 8.4 | 2813.99 ms | 42.23 ms | 66.63x | 48.24 ms | 47.15 ms |
| PHP 8.5 | 2830.70 ms | 42.59 ms | 66.47x | 48.52 ms | 47.51 ms |
| lowest-supported | 11423.43 ms | 147.82 ms | 77.28x | 156.34 ms | 156.24 ms |

Every valid lane reported:

```text
cards=85
shared_artifact_mib=32
snapshots_equal=yes
invalid_isolated=yes
home_status=200
board_status=200
```

The lowest-supported lane explicitly resolved and installed
`voku/agent-loop 0.20.52`.

The final CI run passed PHP 8.3, 8.4, 8.5 and lowest-supported, including
PHPUnit, PHPStan, template lint and php-cs-fixer. The optional Runner matrix
also passed with Runner both installed and absent.

## Interpretation

The proof establishes that, for a workload dominated by an expensive shared
owner artifact, the bounded batch path avoids repeating that shared work for
every card while preserving projection semantics.

The measured 66x-79x range is specific to this synthetic stress fixture. It
must not be presented as a universal `agent-ui` speed-up. Real projects depend
on artifact sizes, storage latency, session inventory, map/search state and
the proportion of per-task work.

The more durable conclusion is architectural:

> Multi-card pages can share bounded owner observations through
> `projectMany()` without introducing a persistent UI-owned lifecycle cache,
> and the batched snapshots remain equal to the corresponding individual
> projections.

## Invalid proof attempts retained for auditability

Two earlier attempts were deliberately excluded from the performance result.

1. The first fixture wrote the large artifact to `.agent-map/search.sqlite`.
   `ProjectLayout::mapRoot()` actually defaults to `.agent-loop/map`, so the
   measured ~1.3x difference did not exercise the intended shared-artifact
   cache and was discarded.
2. After correcting the path, one run failed because the temporary proof test
   missed the `voku\\AgentLoop\\ProjectLayout` import. That run was also
   discarded.

The final numbers above come only from the corrected proof commit and its
fully green Actions runs.

## Why the stress test is not retained in normal CI

The existing `WorkflowProjectionBatchTest` already protects the production
semantics: input ordering, equality with individual projections, invalid-task
isolation, no cross-call state and rendering of Home/Board.

Keeping an additional 32 MiB stress fixture in every PHP/lowest-supported CI
lane would add recurring compute cost while duplicating those correctness
assertions. This document therefore retains the reproducible evidence and run
links without turning a one-off stress harness into permanent CI ballast.
