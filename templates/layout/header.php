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
