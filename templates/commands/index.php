<?php

use voku\AgentLoop\Cli\CommandDescriptor;
use voku\AgentLoop\Cli\CommandGroup;
use voku\AgentLoop\Cli\CommandOwner;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{
 *     commands: list<CommandDescriptor>,
 *     grouped: array<string, list<CommandDescriptor>>,
 *     groups: list<CommandGroup>,
 *     owners: list<CommandOwner>,
 *     selectedGroup: string,
 *     selectedOwner: string,
 *     query: string,
 *     totalCount: int
 * } $model */
$commands = $model['commands'];
$grouped = $model['grouped'];
$groups = $model['groups'];
$owners = $model['owners'];
$selectedGroup = $model['selectedGroup'];
$selectedOwner = $model['selectedOwner'];
$query = $model['query'];
$totalCount = $model['totalCount'];

$title = 'Commands · agent-ui';
$nav = 'commands';
$projectLabel = 'command reference';
require __DIR__ . '/../layout/header.php';
?>
<div class="page-head">
    <span class="page-head__id">Command Catalog · agent-loop</span>
    <h1>Commands Reference</h1>
    <p class="lede">Authoritative catalog of all <code>agent-loop</code> commands and capabilities. The UI is a pure consumer and does not invent commands or alter owner semantics.</p>
</div>

<nav class="board-switcher" style="margin-bottom: 20px;">
    <a class="board-switcher__tab<?= $selectedGroup === 'all' ? ' board-switcher__tab--active' : '' ?>" href="/commands<?= $selectedOwner !== 'all' || $query !== '' ? '?' . http_build_query(array_filter(['owner' => $selectedOwner !== 'all' ? $selectedOwner : null, 'q' => $query !== '' ? $query : null])) : '' ?>">
        All Commands <span class="board-switcher__count"><?= (int) $totalCount ?></span>
    </a>
    <?php foreach ($groups as $group): ?>
        <?php
        $tabParams = ['group' => $group->value];
        if ($selectedOwner !== 'all') {
            $tabParams['owner'] = $selectedOwner;
        }
        if ($query !== '') {
            $tabParams['q'] = $query;
        }
        $tabHref = '/commands?' . http_build_query($tabParams);
        ?>
        <a class="board-switcher__tab<?= $selectedGroup === $group->value ? ' board-switcher__tab--active' : '' ?>" href="<?= TemplateRenderer::escape($tabHref) ?>">
            <?= TemplateRenderer::escape($group->title()) ?>
        </a>
    <?php endforeach; ?>
</nav>

<section class="panel" style="margin-bottom: 20px;">
    <form class="form" method="get" action="/commands">
        <div class="form__row" style="align-items: flex-end;">
            <label class="field field--wide">
                <span>Filter commands</span>
                <input name="q" value="<?= TemplateRenderer::escape($query) ?>" placeholder="Search command name, summary, usage, or owner...">
            </label>
            <label class="field">
                <span>Group</span>
                <select name="group">
                    <option value="all"<?= $selectedGroup === 'all' ? ' selected' : '' ?>>All groups</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= TemplateRenderer::escape($group->value) ?>"<?= $selectedGroup === $group->value ? ' selected' : '' ?>><?= TemplateRenderer::escape($group->title()) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Owner</span>
                <select name="owner">
                    <option value="all"<?= $selectedOwner === 'all' ? ' selected' : '' ?>>All owners</option>
                    <?php foreach ($owners as $owner): ?>
                        <option value="<?= TemplateRenderer::escape($owner->value) ?>"<?= $selectedOwner === $owner->value ? ' selected' : '' ?>><?= TemplateRenderer::escape($owner->value) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn--primary" type="submit">Filter</button>
            <?php if ($query !== '' || $selectedGroup !== 'all' || $selectedOwner !== 'all'): ?>
                <a class="btn" href="/commands">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php if ($commands !== []): ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:24px" aria-label="Command jump navigation">
        <?php foreach ($commands as $cmd): ?>
            <a class="pill pill--neutral mono" href="#<?= TemplateRenderer::escape($cmd->id->value) ?>"><?= TemplateRenderer::escape($cmd->id->value) ?></a>
        <?php endforeach; ?>
    </div>

    <?php foreach ($groups as $group): ?>
        <?php $groupCommands = $grouped[$group->value] ?? []; ?>
        <?php if ($groupCommands === []): continue; endif; ?>

        <p class="eyebrow"><?= TemplateRenderer::escape($group->title()) ?> (<?= count($groupCommands) ?>)</p>
        <div class="stack" style="gap:16px;margin-bottom:28px">
            <?php foreach ($groupCommands as $cmd): ?>
                <section class="panel command-card" id="<?= TemplateRenderer::escape($cmd->id->value) ?>">
                    <div class="action__head" style="justify-content: space-between;">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <h2 style="margin:0"><code class="mono" style="font-size:18px;font-weight:700"><?= TemplateRenderer::escape($cmd->id->value) ?></code></h2>
                            <span class="pill pill--ok"><?= TemplateRenderer::escape($cmd->group->title()) ?></span>
                            <span class="pill pill--neutral"><?= TemplateRenderer::escape($cmd->owner->value) ?></span>
                        </div>
                        <a class="small muted mono" href="#<?= TemplateRenderer::escape($cmd->id->value) ?>" title="Direct link to this command">#<?= TemplateRenderer::escape($cmd->id->value) ?></a>
                    </div>
                    <p style="margin:10px 0 0;font-size:14px"><?= TemplateRenderer::escape($cmd->summary) ?></p>
                    <?php if ($cmd->usage !== null): ?>
                        <div style="margin-top:12px">
                            <span class="small faint" style="font-weight:600">Usage</span>
                            <div class="codeblock" style="margin-top:4px">
                                <pre id="usage-<?= TemplateRenderer::escape(str_replace(':', '-', $cmd->id->value)) ?>"><?= TemplateRenderer::escape($cmd->usage) ?></pre>
                                <button type="button" class="copy" hidden data-copy-target="usage-<?= TemplateRenderer::escape(str_replace(':', '-', $cmd->id->value)) ?>">Copy</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <section class="panel">
        <p class="empty">No commands match your filter criteria.</p>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
