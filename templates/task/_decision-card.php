<?php

use voku\AgentUi\Integration\AgentLoop\DecisionCardSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/**
 * agent-loop's decision card, printed as Loop wrote it, ahead of the decision forms.
 *
 * Every sentence below comes from the owner. agent-ui adds headings and layout
 * only: it does not shorten, reorder or reinterpret the subject, and a choice's
 * command is shown exactly, including its placeholders. The forms underneath
 * remain the only way this page records anything.
 *
 * @var DecisionCardSnapshot $decisionCard
 * @var string               $decisionCardDomId unique prefix for copy targets
 */
?>
<article class="decision-card" aria-label="<?= TemplateRenderer::escape($decisionCard->title) ?>">
    <p class="provenance provenance--authority">agent-loop · decision card</p>
    <h3 class="decision-card__title" lang="<?= TemplateRenderer::escape($decisionCard->language) ?>"><?= TemplateRenderer::escape($decisionCard->title) ?></h3>
    <?php if ($decisionCard->language !== $decisionCard->requestedLanguage): ?>
        <p class="small note">agent-loop wrote this card in <code><?= TemplateRenderer::escape($decisionCard->language) ?></code>; <code><?= TemplateRenderer::escape($decisionCard->requestedLanguage) ?></code> was requested. The text is shown as the owner wrote it.</p>
    <?php endif; ?>

    <?php if ($decisionCard->subject !== []): ?>
        <dl class="decision-facts">
            <?php foreach ($decisionCard->subject as $fact): ?>
                <div><dt><?= TemplateRenderer::escape($fact['label']) ?></dt><dd><?= TemplateRenderer::escape($fact['value']) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>

    <div class="decision-card__grid">
        <section>
            <h4>Why now <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($decisionCard->whyNowKind)) ?>"><?= TemplateRenderer::escape(Presentation::label($decisionCard->whyNowKind)) ?></span></h4>
            <p class="small"><?= TemplateRenderer::escape($decisionCard->whyNowSummary) ?></p>
            <?php if ($decisionCard->whyNowChanges !== []): ?>
                <ul class="small"><?php foreach ($decisionCard->whyNowChanges as $change): ?><li><?= TemplateRenderer::escape($change) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </section>
        <section>
            <h4>May change</h4>
            <?php if ($decisionCard->mayChange === []): ?>
                <p class="small faint">none listed</p>
            <?php else: ?>
                <p><?php foreach ($decisionCard->mayChange as $path): ?><code class="decision-facts__chip"><?= TemplateRenderer::escape($path) ?></code><?php endforeach; ?></p>
            <?php endif; ?>
        </section>
        <section>
            <h4>Out of scope</h4>
            <?php if ($decisionCard->outOfScope === []): ?>
                <p class="small faint">none listed</p>
            <?php else: ?>
                <ul class="small"><?php foreach ($decisionCard->outOfScope as $item): ?><li><?= TemplateRenderer::escape($item) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </section>
        <section>
            <h4>Proof</h4>
            <p class="small">Validation <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($decisionCard->validationState)) ?>"><?= TemplateRenderer::escape(Presentation::label($decisionCard->validationState)) ?></span>
                <?php if ($decisionCard->validationDetail !== null): ?> <?= TemplateRenderer::escape($decisionCard->validationDetail) ?><?php endif; ?></p>
            <?php if ($decisionCard->plannedValidation !== []): ?>
                <p class="small">Planned: <?php foreach ($decisionCard->plannedValidation as $command): ?><code class="decision-facts__chip"><?= TemplateRenderer::escape($command) ?></code><?php endforeach; ?></p>
            <?php endif; ?>
            <p class="small">Review <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($decisionCard->reviewState)) ?>"><?= TemplateRenderer::escape(Presentation::label($decisionCard->reviewState)) ?></span>
                <?php if ($decisionCard->reviewDetail !== null): ?> <?= TemplateRenderer::escape($decisionCard->reviewDetail) ?><?php endif; ?></p>
        </section>
    </div>

    <p class="decision-card__authorizes"><strong><?= TemplateRenderer::escape($decisionCard->authorizes) ?></strong></p>

    <?php if ($decisionCard->choices !== []): ?>
        <h4>Choices</h4>
        <ul class="decision-card__choices">
            <?php foreach ($decisionCard->choices as $index => $choice): ?>
                <li>
                    <strong><?= TemplateRenderer::escape($choice['label']) ?></strong>
                    <span class="small"><?= TemplateRenderer::escape($choice['effect']) ?></span>
                    <?php if ($choice['command'] !== null): ?>
                        <?php $choiceDomId = $decisionCardDomId . '-choice-' . $index; ?>
                        <div class="codeblock">
                            <pre id="<?= TemplateRenderer::escape($choiceDomId) ?>"><?= TemplateRenderer::escape($choice['command']) ?></pre>
                            <button type="button" class="copy" hidden data-copy-target="<?= TemplateRenderer::escape($choiceDomId) ?>">Copy</button>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($decisionCard->unknown !== []): ?>
        <h4>Not known to agent-loop</h4>
        <ul class="small"><?php foreach ($decisionCard->unknown as $item): ?><li><?= TemplateRenderer::escape($item) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
</article>
