<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\States;

use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\RiskAnalysisAgent;
use TimMcLeod\AgentWorkflows\WorkflowState;

class ReviewState extends WorkflowState
{
    public function document(): string
    {
        return (string) $this->get('doc', '');
    }

    public function riskScore(): int
    {
        return (int) $this->output(RiskAnalysisAgent::class)?->structured('riskScore', 0);
    }

    public function isHighRisk(): bool
    {
        return $this->riskScore() > 7;
    }

    public function recordDecision(string $decision): static
    {
        return $this->set('decision', $decision);
    }
}
