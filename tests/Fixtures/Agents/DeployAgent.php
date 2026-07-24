<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;
use Stringable;

class DeployAgent implements Agent, Conversational
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Deploy applications when asked.';
    }

    public function messages(): iterable
    {
        return [];
    }
}
