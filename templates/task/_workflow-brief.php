<?php

use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjection;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowProgressSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{card: CardSnapshot, progress: WorkflowProgressSnapshot, contract: ?TaskContract, human_decisions: WorkflowHumanDecisionProjection, pending_attention?: ?\voku\AgentLoop\Execution\AttentionRequest} $model */
$card = $model['card'];
$progress = $model['progress'];
$contract = $model['contract'];
$decisions = $model['human_decisions'];
$pendingAttention = $model['pending_attention'] ?? null;

$activeStep = null;
foreach ($progress->steps as $step) {
    if ($step->status === 'blocked' || $step->status === 'current') {
        $activeStep = $step;
        break;
    }
}
$humanRequired = $decisions->actions !== [] || $pendingAttention !== null;
$contractApproved = $contract !== null && $contract->status === TaskContract::APPROVED;
?>
<section class="panel workflow-brief" aria-label="Current task briefing">
    <div class="action__head">
        <div>
            <p class="eyebrow" style="margin:0">Developer briefing · owner-backed</p>
            <h2 class="workflow-brief__goal"><?= TemplateRenderer::escape($contract?->goal ?? $card->title) ?></h2>
        </div>
        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($progress->state)) ?>"><?= TemplateRenderer::escape(Presentation::label($progress->state)) ?></span>
    </div>

    <div class="workflow-brief__facts">
        <div>
            <span class="small faint">Current workflow stage</span>
            <strong><?= TemplateRenderer::escape($activeStep?->label ?? 'No active timeline stage') ?></strong>
            <?php if ($activeStep?->reason !== null): ?>
                <p class="small note"><?= TemplateRenderer::escape($activeStep->reason) ?></p>
            <?php endif; ?>
        </div>
        <div>
            <span class="small faint">Contract authority</span>
            <strong><?= $contract === null ? 'No Contract yet' : ($contractApproved ? 'Approved revision ' : 'Not approved · revision ') . ($contract !== null ? (int) $contract->revision : '') ?></strong>
            <p class="small note"><?= $humanRequired ? 'A human decision is available below.' : 'No recordable human decision is currently projected.' ?></p>
        </div>
    </div>

    <div class="workflow-brief__next">
        <span class="small faint">What needs to happen next · agent-loop</span>
        <p class="workflow-brief__hint"><?= TemplateRenderer::escape(Presentation::nextActionKindHint($progress->nextActionKind)) ?></p>
        <p class="small note">The exact command or instruction is shown in the canonical next-action panel above. This briefing does not execute it.</p>
    </div>

    <?php if ($contract !== null): ?>
        <details class="workflow-brief__contract">
            <summary>Approved or proposed boundaries · <?= (int) $contract->revision ?></summary>
            <div class="workflow-brief__facts">
                <div>
                    <span class="small faint">Files and paths in scope (<?= count($contract->scope) ?>)</span>
                    <?php foreach ($contract->scope as $path): ?>
                        <code class="workflow-brief__line"><?= TemplateRenderer::escape($path) ?></code>
                    <?php endforeach; ?>
                </div>
                <div>
                    <span class="small faint">Required validation (not a passing result)</span>
                    <?php foreach ($contract->validation as $command): ?>
                        <code class="workflow-brief__line"><?= TemplateRenderer::escape($command) ?></code>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($contract->nonGoals !== []): ?>
                <p class="small"><strong>Out of scope:</strong> <?= TemplateRenderer::escape(implode('; ', $contract->nonGoals)) ?></p>
            <?php endif; ?>
            <?php if ($contract->acceptanceCriteria !== []): ?>
                <p class="small"><strong>Acceptance:</strong> <?= TemplateRenderer::escape(implode('; ', $contract->acceptanceCriteria)) ?></p>
            <?php endif; ?>
            <p class="small note">Planned validation is not execution evidence. Check the evidence and review views for actual results.</p>
        </details>
    <?php endif; ?>

    <nav class="workflow-brief__links" aria-label="Task workbench navigation">
        <?php if ($humanRequired): ?><a class="btn btn--primary btn--small" href="#workflow-approval">Review the decision</a><?php endif; ?>
        <a class="btn btn--small" href="#workflow-graph">Workflow graph</a>
        <a class="btn btn--small" href="/task/<?= rawurlencode($card->id) ?>/evidence">Evidence</a>
        <a class="btn btn--small" href="/task/<?= rawurlencode($card->id) ?>/work">Work &amp; review</a>
        <a class="btn btn--small" href="/task/<?= rawurlencode($card->id) ?>/prompts?recipe=continue-until-done">Prompts</a>
        <?php if (getenv('AGENT_UI_TERMINAL_TASK') === $card->id && \voku\AgentUi\Runtime\LocalTerminal::origin() !== null): ?>
            <a class="btn btn--small" href="#workflow-terminal">Browser terminal</a>
        <?php endif; ?>
    </nav>
    <p class="small faint">Source: agent-loop workflow projection + exact Contract. Terminal output is not used to infer progress, approval, or verification.</p>
</section>
