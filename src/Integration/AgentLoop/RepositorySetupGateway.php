<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use InvalidArgumentException;
use voku\AgentLoop\Init\ManagedAssetChangePlan;
use voku\AgentLoop\Init\ManagedAssetMutationResult;
use voku\AgentLoop\Init\RepositorySetupOperation;
use voku\AgentLoop\Init\RepositorySetupProjection;
use voku\AgentLoop\Init\RepositorySetupService;

final readonly class RepositorySetupGateway
{
    private RepositorySetupService $service;

    public function __construct(string $projectRoot)
    {
        $this->service = new RepositorySetupService($projectRoot);
    }

    public function overview(?string $agent = null): RepositorySetupProjection
    {
        return $this->service->overview($agent);
    }

    /** @return list<RepositorySetupOperation> */
    public function legalOperations(?string $agent = null): array
    {
        return $this->service->legalOperations($agent);
    }

    public function installPlan(string $agent, bool $withHooks = false): ManagedAssetChangePlan
    {
        return $this->service->planInstall($agent, $withHooks);
    }

    public function removePlan(string $agent, bool $withHooks = false): ManagedAssetChangePlan
    {
        return $this->service->planUninstall($agent, $withHooks);
    }

    public function install(
        string $agent,
        string $planId,
        string $expectedState,
        bool $withHooks = false,
    ): ManagedAssetMutationResult {
        $plan = $this->service->planInstall($agent, $withHooks);
        $this->assertPlan($plan, $planId, $expectedState);

        return $this->service->install($plan, $expectedState);
    }

    public function remove(
        string $agent,
        string $planId,
        string $expectedState,
        bool $withHooks = false,
    ): ManagedAssetMutationResult {
        $plan = $this->service->planUninstall($agent, $withHooks);
        $this->assertPlan($plan, $planId, $expectedState);

        return $this->service->uninstall($plan, $expectedState);
    }

    public function syncPolicy(string $agent): RepositorySetupProjection
    {
        return $this->service->syncPolicy($agent);
    }

    public function syncGitIntegration(): RepositorySetupProjection
    {
        return $this->service->syncGitIntegration();
    }

    private function assertPlan(ManagedAssetChangePlan $plan, string $planId, string $expectedState): void
    {
        if ($planId === '' || !hash_equals($plan->planId(), $planId)) {
            throw new InvalidArgumentException('Setup plan changed. Review the current plan before applying it.');
        }
        if ($expectedState === '' || !$plan->expectedState->matches($expectedState)) {
            throw new InvalidArgumentException('Setup state changed. Review the current plan before applying it.');
        }
    }
}
