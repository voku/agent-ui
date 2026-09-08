<?php
/**
 * Renders one bounded, hash-verified source window.
 *
 * Included with a `$view` in scope. A window that agent-map refused to
 * materialize renders its refusal instead of its lines, because a stale or
 * unindexed file has no source this page is entitled to show.
 */

use voku\AgentUi\Integration\AgentMap\SourceView;
use voku\AgentUi\View\TemplateRenderer;

/** @var SourceView $view */
?>
<?php if (!$view->isRendered()): ?>
    <p class="note source__note source__note--<?= TemplateRenderer::escape($view->status) ?>">
        <?php if ($view->status === 'stale'): ?>
            <strong>Source not shown.</strong>
            <?php if ($view->staleReason === 'missing'): ?>
                agent-map records <code><?= TemplateRenderer::escape($view->path) ?></code> as stale because the indexed file is gone.
            <?php elseif ($view->staleReason === 'hash'): ?>
                agent-map records <code><?= TemplateRenderer::escape($view->path) ?></code> as stale because its contents changed since the map was built.
            <?php else: ?>
                agent-map records <code><?= TemplateRenderer::escape($view->path) ?></code> as stale.
            <?php endif; ?>
            Refresh with <code>vendor/bin/agent-map refresh --root=.</code>.
        <?php elseif ($view->status === 'not_indexed'): ?>
            <strong>Not in the map.</strong> <code><?= TemplateRenderer::escape($view->path) ?></code> is outside the indexed paths.
        <?php elseif ($view->status === 'missing'): ?>
            <strong>No map index.</strong> Build one with <code>vendor/bin/agent-map build --root=.</code>.
        <?php else: ?>
            <strong>Source unavailable.</strong> agent-map could not materialize this window and does not record the file as stale:
            <span class="mono"><?= TemplateRenderer::escape($view->failure ?? 'no reason reported.') ?></span>
        <?php endif; ?>
    </p>
<?php else: ?>
    <div class="source">
        <?php if ($view->hasMoreBefore): ?>
            <p class="source__edge">… <?= (int) ($view->lineStart - 1) ?> earlier line(s) not shown</p>
        <?php endif; ?>
        <ol class="source__lines" start="<?= (int) $view->lineStart ?>">
            <?php foreach ($view->lines as $line): ?>
                <li class="source__line<?= $line->focus ? ' source__line--focus' : '' ?>" value="<?= (int) $line->number ?>" id="L<?= (int) $line->number ?>"><code><?= TemplateRenderer::escape($line->text) ?></code></li>
            <?php endforeach; ?>
        </ol>
        <?php if ($view->hasMoreAfter): ?>
            <p class="source__edge">… more lines below</p>
        <?php endif; ?>
    </div>
<?php endif; ?>
