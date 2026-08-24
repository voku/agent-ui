<?php

declare(strict_types=1);

namespace voku\AgentUi\View;

use RuntimeException;

final readonly class TemplateRenderer
{
    public function __construct(private string $templateRoot)
    {
    }

    /** @param array<string, mixed> $model */
    public function render(string $template, array $model): string
    {
        $path = $this->templateRoot . '/' . $template . '.php';
        if (!is_file($path)) {
            throw new RuntimeException('Template not found: ' . $template);
        }

        ob_start();
        try {
            require $path;
            $content = ob_get_contents();
            if (!is_string($content)) {
                throw new RuntimeException('Unable to render template: ' . $template);
            }
        } finally {
            ob_end_clean();
        }

        return $content;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
