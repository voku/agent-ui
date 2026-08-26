<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Setup;

use InvalidArgumentException;
use voku\AgentLoop\Init\RepositorySetupOperation;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentLoop\RepositorySetupGateway;
use voku\AgentUi\Security\CsrfTokenManager;
use voku\AgentUi\View\TemplateRenderer;

final readonly class SetupAction
{
    /** @var list<string> */
    private const array HOSTS = ['codex', 'claude', 'opencode', 'copilot', 'gemini', 'antigravity'];

    public function __construct(
        private RepositorySetupGateway $setup,
        private CsrfTokenManager $csrf,
        private TemplateRenderer $templates,
    ) {
    }

    public function overview(): Response
    {
        $hosts = [];
        foreach (self::HOSTS as $host) {
            try {
                $projection = $this->setup->overview($host);
                $legal = $this->setup->legalOperations($host);
                $install = $this->setup->installPlan($host);
                $remove = $this->setup->removePlan($host);
                $hosts[$host] = [
                    'projection' => $projection,
                    'legal' => $legal,
                    'install' => $install,
                    'remove' => $remove,
                ];
            } catch (InvalidArgumentException $exception) {
                $hosts[$host] = ['error' => $exception->getMessage()];
            }
        }

        return Response::html($this->templates->render('setup/index', [
            'hosts' => $hosts,
            'csrf' => $this->csrf->token(),
        ]));
    }

    public function mutate(string $route, string $agent, Request $request): Response
    {
        $this->csrf->assertValid($request->body['_csrf'] ?? null);

        match ($route) {
            'setup_install' => $this->install($agent, $request),
            'setup_remove' => $this->remove($agent, $request),
            'setup_sync_policy' => $this->syncPolicy($agent),
            'setup_sync_git' => $this->syncGit(),
            default => throw new InvalidArgumentException('Unknown setup action.'),
        };

        return Response::redirect('/setup');
    }

    private function install(string $agent, Request $request): void
    {
        $this->requireLegal($agent, [RepositorySetupOperation::INSTALL_ASSETS, RepositorySetupOperation::UPDATE_ASSETS]);
        $this->setup->install(
            $agent,
            $request->body['plan_id'] ?? '',
            $request->body['expected_state'] ?? '',
            ($request->body['with_hooks'] ?? '') === '1',
        );
    }

    private function remove(string $agent, Request $request): void
    {
        $this->requireLegal($agent, [RepositorySetupOperation::REMOVE_ASSETS]);
        $this->setup->remove(
            $agent,
            $request->body['plan_id'] ?? '',
            $request->body['expected_state'] ?? '',
            ($request->body['with_hooks'] ?? '') === '1',
        );
    }

    private function syncPolicy(string $agent): void
    {
        $this->requireLegal($agent, [RepositorySetupOperation::SYNC_POLICY]);
        $this->setup->syncPolicy($agent);
    }

    private function syncGit(): void
    {
        $legal = [];
        foreach (self::HOSTS as $host) {
            try {
                $legal = [...$legal, ...$this->setup->legalOperations($host)];
            } catch (InvalidArgumentException) {
            }
        }
        if (!in_array(RepositorySetupOperation::SYNC_GIT_INTEGRATION, $legal, true)) {
            throw new InvalidArgumentException('Git integration sync is not currently a legal owner action.');
        }
        $this->setup->syncGitIntegration();
    }

    /** @param list<RepositorySetupOperation> $accepted */
    private function requireLegal(string $agent, array $accepted): void
    {
        foreach ($this->setup->legalOperations($agent) as $operation) {
            if (in_array($operation, $accepted, true)) {
                return;
            }
        }

        throw new InvalidArgumentException('That setup action is not currently legal for this host.');
    }
}
