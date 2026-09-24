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
<?php
/*
 * The agent-loop infinity mark: one tangent-matched lemniscate stroked with the
 * brand gradient. Inlined, like the stylesheet, so the control plane stays a
 * single request with no asset route; the favicon is the same path on the
 * banner's navy tile.
 */
$loopPath = 'M 80 25 C 52.4 25, 30 47.4, 30 75 C 30 102.6, 52.4 125, 80 125 C 115 125, 132 95, 150 75 C 168 55, 185 25, 220 25 C 247.6 25, 270 47.4, 270 75 C 270 102.6, 247.6 125, 220 125 C 185 125, 168 95, 150 75 C 132 55, 115 25, 80 25 Z';
$loopStops = '<stop offset="0%" stop-color="#7C3AED"/><stop offset="22%" stop-color="#6366F1"/><stop offset="42%" stop-color="#2563EB"/><stop offset="70%" stop-color="#0284C7"/><stop offset="100%" stop-color="#00D2FF"/>';
$favicon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><linearGradient id="g" x1="0%" y1="60%" x2="100%" y2="40%">' . $loopStops . '</linearGradient></defs>'
    . '<rect width="64" height="64" rx="14" fill="#0A0F1D"/><g transform="translate(4 18) scale(0.187)"><path d="' . $loopPath . '" stroke="url(#g)" stroke-width="32" stroke-linecap="round" stroke-linejoin="round" fill="none"/></g></svg>';
?>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<?= rawurlencode($favicon) ?>">
<meta name="theme-color" content="#001038">
<style><?= file_get_contents(__DIR__ . '/app.css') ?></style>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="masthead">
    <div class="masthead__inner">
        <a class="brand" href="/" aria-label="agent-ui, agent-loop control plane — Overview">
            <svg class="brand__mark" viewBox="0 0 300 150" fill="none" aria-hidden="true" focusable="false">
                <defs><linearGradient id="brand-loop-gradient" x1="0%" y1="60%" x2="100%" y2="40%"><?= $loopStops ?></linearGradient></defs>
                <path d="<?= $loopPath ?>" stroke="url(#brand-loop-gradient)" stroke-width="30" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span class="brand__text"><span class="brand__name">agent-ui</span><span class="brand__tag">agent-loop control plane</span></span>
        </a>
        <?php
        /**
         * Grouped by the question a developer is asking, not by which package owns
         * the answer. A flat `Overview | Setup | Prompts | Board | Map | Knowledge`
         * row made every concept a peer, so choosing required knowing the
         * architecture first. Daily work (Work, Knowledge) leads; the tools that
         * support it stay one click away instead of competing for the same
         * attention. Routes and URLs are unchanged - this is presentation only.
         */
        $workspaceSections = [
            'Work' => [
                ['/', 'Overview', 'home'],
                ['/board', 'Tasks', 'board'],
            ],
            'Knowledge' => [
                ['/knowledge', 'Knowledge', 'knowledge'],
            ],
            'Code' => [
                ['/map', 'Map', 'map'],
            ],
            'Tools' => [
                ['/prompts', 'Prompts', 'prompts'],
                ['/commands', 'Commands', 'commands'],
                ['/setup', 'Setup', 'setup'],
            ],
        ];
        ?>
        <nav class="workspace-nav" aria-label="Primary">
            <?php foreach ($workspaceSections as $section => $links): ?>
                <?php
                $sectionCurrent = false;
                foreach ($links as [, , $key]) {
                    $sectionCurrent = $sectionCurrent || $nav === $key;
                }
                ?>
                <div class="workspace-nav__group<?= $sectionCurrent ? ' workspace-nav__group--current' : '' ?>">
                    <span class="workspace-nav__label" aria-hidden="true"><?= TemplateRenderer::escape($section) ?></span>
                    <ul class="workspace-nav__links">
                        <?php foreach ($links as [$href, $label, $key]): ?>
                            <li><a href="<?= $href ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>><?php
                                /* The group name is decoration for sighted scanning; the
                                   accessible name carries it so a screen reader hears the
                                   same grouping the eye gets. */
                                ?><span class="visually-hidden"><?= TemplateRenderer::escape($section) ?>: </span><?= TemplateRenderer::escape($label) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
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
