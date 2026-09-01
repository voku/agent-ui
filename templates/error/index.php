<?php
use voku\AgentUi\View\TemplateRenderer;
/** @var array{status: int, heading: string, message: string} $model */
$status = $model['status'];
$heading = $model['heading'];
$message = $model['message'];
$title = $heading . ' · agent-ui';
$nav = null;
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<div class="page-head">
    <span class="page-head__id"><?= (int) $status ?></span>
    <h1><?= TemplateRenderer::escape($heading) ?></h1>
</div>
<section class="panel panel--danger">
    <p><?= TemplateRenderer::escape($message) ?></p>
    <p class="note">This is the UI reporting its own request handling. No owner state was read or changed.</p>
</section>
<div class="btn-row">
    <a class="btn btn--primary" href="/">Overview</a>
    <a class="btn" href="/board">Board</a>
    <a class="btn" href="/setup">Setup</a>
    <a class="btn" href="/knowledge">Knowledge</a>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
