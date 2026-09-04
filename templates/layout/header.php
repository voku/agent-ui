<?php
use voku\AgentUi\Http\FlashNotice;
use voku\AgentUi\View\TemplateRenderer;
/** @var string $title */
/** @var string|null $nav */
/** @var string|null $projectLabel */
$nav ??= null;
$projectLabel ??= null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= TemplateRenderer::escape($title) ?></title>
<style><?= file_get_contents(__DIR__ . '/app.css') ?></style>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="masthead">
    <div class="masthead__inner">
        <a class="brand" href="/"><span class="brand__mark" aria-hidden="true"></span>agent-ui</a>
        <nav aria-label="Primary">
            <a href="/"<?= $nav === 'home' ? ' aria-current="page"' : '' ?>>Overview</a>
            <a href="/setup"<?= $nav === 'setup' ? ' aria-current="page"' : '' ?>>Setup</a>
            <a href="/prompts"<?= $nav === 'prompts' ? ' aria-current="page"' : '' ?>>Prompts</a>
            <a href="/board"<?= $nav === 'board' ? ' aria-current="page"' : '' ?>>Board</a>
            <a href="/map"<?= $nav === 'map' ? ' aria-current="page"' : '' ?>>Map</a>
            <a href="/knowledge"<?= $nav === 'knowledge' ? ' aria-current="page"' : '' ?>>Knowledge</a>
        </nav>
        <?php if ($projectLabel !== null): ?>
            <div class="masthead__meta"><span class="mono"><?= TemplateRenderer::escape($projectLabel) ?></span></div>
        <?php endif; ?>
    </div>
</header>
<main class="wrap" id="main">
<?php $notice = (new FlashNotice())->take(); ?>
<?php if ($notice !== null): ?>
    <p class="notice" role="status"><?= TemplateRenderer::escape($notice) ?></p>
<?php endif; ?>
