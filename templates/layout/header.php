<?php
use voku\AgentUi\View\TemplateRenderer;
/** @var string $title */
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= TemplateRenderer::escape($title) ?></title>
<style>
:root{font-family:system-ui,sans-serif;color:#202124;background:#f6f7f8}body{margin:0}header{background:#111827;color:#fff;padding:1rem 2rem}header a{color:#fff;margin-right:1rem}.wrap{max-width:1200px;margin:0 auto;padding:1.5rem}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem}.card,.panel{background:#fff;border:1px solid #dfe3e8;border-radius:10px;padding:1rem}.lane h2,.muted{color:#667085}.status{font-weight:700}.attention{border-left:4px solid #9a6700}.danger{border-left:4px solid #b42318}code,pre{background:#f2f4f7;border-radius:6px}pre{padding:1rem;overflow:auto}dt{font-weight:700}dd{margin:0 0 .8rem}a.button{display:inline-block;padding:.55rem .8rem;border-radius:7px;background:#111827;color:#fff;text-decoration:none}
</style></head><body><header><strong>Agent UI</strong> <nav style="display:inline"><a href="/">Home</a><a href="/board">Board</a></nav></header><main class="wrap">
