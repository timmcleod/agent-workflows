<?php

namespace TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

class SummarizeAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Summarize the given document.';
    }
}
