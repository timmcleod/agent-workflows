<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class BearCaseAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Argue the strongest case against.';
    }
}
