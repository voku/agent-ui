<?php
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{card: CardSnapshot, contract: ?TaskContract, csrf_token: string} $model */
$card = $model['card'];
$contract = $model['contract'];
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
