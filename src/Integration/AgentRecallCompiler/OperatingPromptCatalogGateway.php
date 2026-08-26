<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentRecallCompiler;

use voku\AgentRecallCompiler\OperatingPromptCatalog;
use voku\AgentRecallCompiler\OperatingPromptPreview;
use voku\AgentRecallCompiler\OperatingPromptRecipe;
use voku\AgentRecallCompiler\OperatingPromptRequest;

/**
 * Thin UI adapter over Recall-owned operating-prompt semantics.
 */
final readonly class OperatingPromptCatalogGateway
{
    private OperatingPromptCatalog $catalog;

    public function __construct()
    {
        $this->catalog = OperatingPromptCatalog::bundled();
    }

    /** @return list<OperatingPromptRecipe> */
    public function recipes(): array
    {
        return $this->catalog->recipes();
    }

    public function recipe(string $id): OperatingPromptRecipe
    {
        return $this->catalog->recipe($id);
    }

    public function preview(OperatingPromptRequest $request): OperatingPromptPreview
    {
        return $this->catalog->preview($request);
    }
}
