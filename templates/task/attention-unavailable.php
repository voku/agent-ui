<?php

use voku\AgentLoop\Execution\AttentionRequest;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{card: CardSnapshot, attention: AttentionRequest} $model */
$card = $model['card'];
$attention = $model['attention'];
$title = $card->id . ' · Attention · agent-ui';
$nav = null;
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/board">Board</a></p>

<div class="page-head">
    <p class="eyebrow">Task overview · <?= TemplateRenderer::escape($card->id) ?></p>
    <h1><?= TemplateRenderer::escape($card->title) ?></h1>
</div>

<section class="panel panel--attention" role="status" aria-labelledby="decision-unavailable">
    <h2 id="decision-unavailable">Workflow projection unavailable</h2>
    <p>agent-loop cannot currently provide the canonical workflow state or next action.
        This view contains only independently observed execution Attention.
        No approval can be submitted until the current owner projection is available.</p>
</section>

<section class="panel panel--attention" id="decision" aria-label="Observed human Attention">
    <h2>Human Attention observed</h2>
    <dl>
        <div><dt>Task</dt><dd><?= TemplateRenderer::escape($attention->taskId) ?></dd></div>
        <div><dt>Attention</dt><dd><?= TemplateRenderer::escape($attention->id) ?></dd></div>
        <div><dt>Kind</dt><dd><?= TemplateRenderer::escape(Presentation::label($attention->kind->value)) ?></dd></div>
        <div><dt>Stage</dt><dd><?= TemplateRenderer::escape($attention->stageId ?? 'unspecified') ?></dd></div>
    </dl>
    <p><?= TemplateRenderer::escape($attention->message) ?></p>
    <p class="note">This message is a read-only observation, not permission to resume execution.</p>
</section>

<p><a class="btn" href="/board">Return to Board</a></p>
<?php require __DIR__ . '/../layout/footer.php'; ?>
