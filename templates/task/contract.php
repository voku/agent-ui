<?php
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentUi\Feature\Task\ContractScopeImpact;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentMap\MapReadinessSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{card: CardSnapshot, contract: ?TaskContract, scope_impact: ContractScopeImpact, map_readiness: MapReadinessSnapshot, csrf_token: string} $model */
$card = $model['card'];
$contract = $model['contract'];
$scopeImpact = $model['scope_impact'];
$mapReadiness = $model['map_readiness'];
$csrf = $model['csrf_token'];

$title = 'Contract · ' . $card->id . ' · ' . $card->title . ' · agent-ui';
$nav = null;
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs">
    <a href="/board">Board</a>
    <span>/</span>
    <a href="/task/<?= TemplateRenderer::escape($card->id) ?>"><?= TemplateRenderer::escape($card->id) ?></a>
    <span>/</span>
    Contract
</p>

<div class="page-head">
    <span class="page-head__id"><?= TemplateRenderer::escape($card->id) ?></span>
    <h1>Task Contract: <?= TemplateRenderer::escape($card->title) ?></h1>
    <p class="lede">The governed execution contract defining goal, scope boundary, validation, and acceptance criteria.</p>
</div>

<?php if ($contract !== null): ?>
    <p class="eyebrow">Current Contract</p>
    <section class="panel<?= $contract->status === TaskContract::APPROVED ? ' panel--accent' : ' panel--attention' ?>">
        <div class="action__head">
            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($contract->status)) ?>">
                <?= TemplateRenderer::escape(Presentation::label($contract->status)) ?>
            </span>
            <span class="small faint">Revision <?= (int) $contract->revision ?> · Planned by <strong><?= TemplateRenderer::escape($contract->plannedBy) ?></strong></span>
        </div>

        <?php if ($contract->status === TaskContract::APPROVED && $contract->approvedBy !== null): ?>
            <div style="margin-top:10px;padding:8px 12px;background:var(--accent-soft);border-radius:6px">
                <span class="small">Approved by <strong><?= TemplateRenderer::escape($contract->approvedBy) ?></strong> at <?= TemplateRenderer::escape($contract->approvedAt ?? '') ?></span>
            </div>
        <?php endif; ?>

        <dl class="kv" style="margin-top:14px">
            <dt>Goal</dt>
            <dd><strong><?= TemplateRenderer::escape($contract->goal) ?></strong></dd>
            <?php if ($contract->baseCommit !== null): ?>
                <dt>Base commit</dt>
                <dd><code class="mono small"><?= TemplateRenderer::escape($contract->baseCommit) ?></code></dd>
            <?php endif; ?>
        </dl>

        <div class="grid" style="margin-top:14px">
            <div>
                <h2>In scope (<?= count($contract->scope) ?>)</h2>
                <?php if ($contract->scope === []): ?>
                    <p class="empty">No paths declared.</p>
                <?php else: ?>
                    <div class="stack">
                        <?php foreach ($contract->scope as $path): ?>
                            <code><?= TemplateRenderer::escape($path) ?></code>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <h2>Explicit non-goals (<?= count($contract->nonGoals) ?>)</h2>
                <?php if ($contract->nonGoals === []): ?>
                    <p class="empty">None recorded.</p>
                <?php else: ?>
                    <div class="stack">
                        <?php foreach ($contract->nonGoals as $item): ?>
                            <p class="small" style="margin:0"><?= TemplateRenderer::escape($item) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <h2>Acceptance criteria (<?= count($contract->acceptanceCriteria) ?>)</h2>
                <?php if ($contract->acceptanceCriteria === []): ?>
                    <p class="empty">None recorded.</p>
                <?php else: ?>
                    <div class="stack">
                        <?php foreach ($contract->acceptanceCriteria as $criterion): ?>
                            <p class="small" style="margin:0">• <?= TemplateRenderer::escape($criterion) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <h2>Validation (<?= count($contract->validation) ?>)</h2>
                <?php if ($contract->validation === []): ?>
                    <p class="empty">No commands declared.</p>
                <?php else: ?>
                    <div class="stack">
                        <?php foreach ($contract->validation as $cmd): ?>
                            <code class="mono small"><?= TemplateRenderer::escape($cmd) ?></code>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($scopeImpact->available): ?>
        <?php // agent-map answered the impact for each indexed path and
              // agent-loop's ApprovedScope answered what is inside the boundary.
              // This section links the two and counts; it decides neither. ?>
        <p class="eyebrow" style="margin-top:20px">What this scope reaches</p>
        <section class="panel<?= $scopeImpact->filesOutsideScopeCount > 0 ? ' panel--attention' : '' ?>" aria-label="What this scope reaches">
            <div class="action__head">
                <div>
                    <strong>
                        <?php if ($scopeImpact->nothingProjected()): ?>
                            agent-map has no facts about any declared path
                        <?php elseif (
                            $scopeImpact->filesOutsideScopeCount === 0
                            && ($scopeImpact->entriesTruncated || $scopeImpact->anyImpactTruncated)
                        ): ?>
                            No outside-scope reach was observed in the bounded analysis
                        <?php elseif ($scopeImpact->filesOutsideScopeCount === 0): ?>
                            No file outside the declared scope reaches it
                        <?php else: ?>
                            <?= (int) $scopeImpact->filesOutsideScopeCount ?> file<?= $scopeImpact->filesOutsideScopeCount === 1 ? '' : 's' ?> outside the declared scope can notice this change
                        <?php endif; ?>
                    </strong>
                    <span class="small faint" style="margin-left:8px">
                        <?= (int) $scopeImpact->indexedEntryCount ?> of <?= count($scopeImpact->entries) ?> declared path(s) indexed by agent-map
                    </span>
                </div>
                <span class="pill pill--neutral">agent-map impact</span>
            </div>

            <p class="note" style="margin-top:10px">
                Reaching outside the declared scope is an observation, not a verdict on the Contract. agent-map answers
                what depends on each indexed path; agent-loop decides what counts as inside the approved boundary.
            </p>
            <?php if ($scopeImpact->nothingProjected()): ?>
                <p class="note">Nothing was projected here, which is not the same as nothing depending on this scope. Build or refresh agent-map before reading the table below as an answer.</p>
            <?php endif; ?>

            <div class="table-scroll" style="margin-top:12px">
                <table class="table">
                    <thead><tr><th>Declared path</th><th>Reaches outside scope</th><th>Inside</th><th>Continue</th></tr></thead>
                    <tbody>
                    <?php foreach ($scopeImpact->entries as $entry): ?>
                        <tr>
                            <td><code><?= TemplateRenderer::escape($entry->path) ?></code></td>
                            <td>
                                <?php if (!$entry->indexed): ?>
                                    <span class="small faint">not indexed by agent-map</span>
                                <?php elseif (!$entry->reachesOutsideScope()): ?>
                                    <span class="small faint">none</span>
                                <?php else: ?>
                                    <span class="small"><strong><?= (int) $entry->reachedOutsideScopeCount ?></strong></span>
                                    <div class="stack" style="margin-top:4px">
                                        <?php foreach ($entry->reachedOutsideScope as $reached): ?>
                                            <code class="small"><a href="/map/source?path=<?= rawurlencode($reached) ?>"><?= TemplateRenderer::escape($reached) ?></a></code>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($entry->listTruncated()): ?>
                                        <p class="small faint" style="margin:4px 0 0">and <?= (int) ($entry->reachedOutsideScopeCount - count($entry->reachedOutsideScope)) ?> more</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($entry->uncertain): ?>
                                    <p class="small faint" style="margin:4px 0 0">some paths are uncertain (dynamic or multiple-target)</p>
                                <?php endif; ?>
                                <?php if ($entry->truncated): ?>
                                    <p class="small faint" style="margin:4px 0 0">agent-map bounded this traversal</p>
                                <?php endif; ?>
                            </td>
                            <td class="small faint"><?= $entry->indexed ? (int) $entry->reachedInsideScopeCount : '&mdash;' ?></td>
                            <td>
                                <?php if ($entry->indexed): ?>
                                    <a class="btn btn--small" href="/map/source?path=<?= rawurlencode($entry->path) ?>">Source</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($scopeImpact->unindexedEntryCount > 0): ?>
                <p class="note"><?= (int) $scopeImpact->unindexedEntryCount ?> declared path(s) are not indexed PHP files — a directory, a manifest, or a file this task has yet to create. That is an absent projection, not a claim that nothing depends on them.</p>
            <?php endif; ?>
            <?php if ($scopeImpact->entriesTruncated): ?>
                <p class="note">Only the first <?= ContractScopeImpact::MAXIMUM_ENTRIES ?> declared paths were analysed.</p>
            <?php endif; ?>
            <?php if ($scopeImpact->anyImpactTruncated): ?>
                <p class="note">At least one traversal hit agent-map's node bound, so the reached set is a lower bound rather than a complete one.</p>
            <?php endif; ?>
            <?php if ($mapReadiness->status === 'stale'): ?>
                <p class="note">The indexed snapshot is behind the working tree. Refresh agent-map before treating this reach as current.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <p class="eyebrow">Revise Contract</p>
    <section class="panel">
        <form class="form" method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/contract">
            <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
            <input type="hidden" name="contract_action" value="revise">

            <h3>Propose Contract Revision <?= (int) ($contract->revision + 1) ?></h3>
            <p class="note" style="margin:0 0 14px">Proposing a revision archives revision <?= (int) $contract->revision ?> and places a new candidate awaiting approval.</p>

            <div class="form__row">
                <label class="field field--wide">
                    <span>Goal *</span>
                    <input required maxlength="1000" name="goal" value="<?= TemplateRenderer::escape($contract->goal) ?>">
                </label>
            </div>

            <div class="grid" style="margin-top:14px">
                <div>
                    <label class="field field--wide">
                        <span>Scope paths * (one per line)</span>
                        <textarea required rows="4" name="scope"><?= TemplateRenderer::escape(implode("\n", $contract->scope)) ?></textarea>
                    </label>
                </div>
                <div>
                    <label class="field field--wide">
                        <span>Validation commands * (one per line)</span>
                        <textarea required rows="4" name="validation"><?= TemplateRenderer::escape(implode("\n", $contract->validation)) ?></textarea>
                    </label>
                </div>
            </div>

            <div class="grid" style="margin-top:14px">
                <div>
                    <label class="field field--wide">
                        <span>Non-goals (one per line)</span>
                        <textarea rows="3" name="non_goals"><?= TemplateRenderer::escape(implode("\n", $contract->nonGoals)) ?></textarea>
                    </label>
                </div>
                <div>
                    <label class="field field--wide">
                        <span>Acceptance criteria (one per line)</span>
                        <textarea rows="3" name="acceptance_criteria"><?= TemplateRenderer::escape(implode("\n", $contract->acceptanceCriteria)) ?></textarea>
                    </label>
                </div>
            </div>

            <div class="form__row" style="margin-top:14px">
                <label class="field">
                    <span>Planned by *</span>
                    <input required maxlength="200" name="planned_by" value="<?= TemplateRenderer::escape($contract->plannedBy) ?>" placeholder="who designed this revision">
                </label>
            </div>

            <div class="btn-row" style="margin-top:16px">
                <button class="btn btn--primary" type="submit">Propose Revision <?= (int) ($contract->revision + 1) ?></button>
                <a class="btn" href="/task/<?= TemplateRenderer::escape($card->id) ?>">Cancel</a>
            </div>
        </form>
    </section>
<?php else: ?>
    <p class="eyebrow">Propose Contract</p>
    <section class="panel">
        <p class="empty" style="margin-bottom:16px">No Contract exists yet for this task. Propose a contract to define the approved boundary before starting work.</p>

        <form class="form" method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/contract">
            <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
            <input type="hidden" name="contract_action" value="propose">

            <div class="form__row">
                <label class="field field--wide">
                    <span>Goal *</span>
                    <input required maxlength="1000" name="goal" value="<?= TemplateRenderer::escape($card->title) ?>" placeholder="What this task will achieve">
                </label>
            </div>

            <div class="grid" style="margin-top:14px">
                <div>
                    <label class="field field--wide">
                        <span>Scope paths * (one per line)</span>
                        <textarea required rows="4" name="scope" placeholder="e.g.&#10;src/Feature/&#10;tests/Unit/"></textarea>
                    </label>
                    <p class="note">At least one path is required.</p>
                </div>
                <div>
                    <label class="field field--wide">
                        <span>Validation commands * (one per line)</span>
                        <textarea required rows="4" name="validation" placeholder="e.g.&#10;composer test&#10;composer run phpstan"><?= TemplateRenderer::escape($card->validation !== '' ? $card->validation : "composer test") ?></textarea>
                    </label>
                    <p class="note">At least one command is required.</p>
                </div>
            </div>

            <div class="grid" style="margin-top:14px">
                <div>
                    <label class="field field--wide">
                        <span>Non-goals (one per line)</span>
                        <textarea rows="3" name="non_goals" placeholder="Explicit out-of-scope boundaries"></textarea>
                    </label>
                </div>
                <div>
                    <label class="field field--wide">
                        <span>Acceptance criteria (one per line)</span>
                        <textarea rows="3" name="acceptance_criteria" placeholder="Concrete required outcomes"></textarea>
                    </label>
                </div>
            </div>

            <div class="form__row" style="margin-top:14px">
                <label class="field">
                    <span>Planned by *</span>
                    <input required maxlength="200" name="planned_by" value="<?= TemplateRenderer::escape($card->assignee ?? '') ?>" placeholder="developer or planner name">
                </label>
            </div>

            <div class="btn-row" style="margin-top:16px">
                <button class="btn btn--primary" type="submit">Propose Contract</button>
                <a class="btn" href="/task/<?= TemplateRenderer::escape($card->id) ?>">Cancel</a>
            </div>
        </form>
    </section>
<?php endif; ?>

<?php $taskNavId = $card->id; $taskNavCurrent = '/contract'; require __DIR__ . '/../layout/task-nav.php'; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
