<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\BearCaseAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\BullCaseAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\DeployAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\SummarizeAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Workflows\ConventionalPromptWorkflow;

it('binds conventional prompt methods across every step surface', function () {
    SummarizeAgent::fake(['Summarized.', 'The memo.']);
    BullCaseAgent::fake(['Bull.']);
    DeployAgent::fake(['Deployed.']);
    BearCaseAgent::fake(['Bear.']);

    $run = ConventionalPromptWorkflow::start(['doc' => 'Q3 intake', 'escalate' => true]);

    expect($run->status)->toBe(RunStatus::Completed);

    // Plain step: the method receives the state.
    SummarizeAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Summarize the intake: Q3 intake');

    // Explicit prompt (positional string) beats the matching method.
    BullCaseAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Explicit wins.');

    // Condition branch target binds by its own id.
    DeployAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Deploy carefully.');

    // Parallel branch: the first way a branch has ever had its own prompt.
    BearCaseAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Argue the bear case.');

    // Evaluate body binds by the loop's alias.
    SummarizeAgent::assertPrompted(fn ($p) => (string) $p->prompt === 'Draft the memo.');
});

it('skips the untaken branch without consulting its prompt method', function () {
    SummarizeAgent::fake(['Summarized.', 'The memo.']);
    BullCaseAgent::fake(['Bull.']);
    BearCaseAgent::fake(['Bear.']);
    DeployAgent::fake(['Deployed.']);

    $run = ConventionalPromptWorkflow::start(['doc' => 'x', 'escalate' => false]);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['steps'])->not->toHaveKey('DeployAgent');
});
