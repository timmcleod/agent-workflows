<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class RiskAnalysisAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Analyze the risk of the given contract.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'riskScore' => $schema->integer()->required(),
        ];
    }
}
