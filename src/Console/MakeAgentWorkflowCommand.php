<?php

namespace TimMcLeod\AgentWorkflows\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:agent-workflow')]
class MakeAgentWorkflowCommand extends GeneratorCommand
{
    protected $name = 'make:agent-workflow';

    protected $description = 'Create a new agent workflow class';

    protected $type = 'Agent workflow';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/agent-workflow.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\AgentWorkflows';
    }
}
