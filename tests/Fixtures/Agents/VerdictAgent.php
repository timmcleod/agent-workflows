<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class VerdictAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Judge the debate and rule on consensus.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'consensus' => $schema->boolean()->required(),
            'summary' => $schema->string()->required(),
        ];
    }
}
