<?php

use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use TimMcLeod\AgentWorkflows\Support\AgentAdapter;
use TimMcLeod\AgentWorkflows\Support\AgentStepResult;

/**
 * Exposes the protected projection for direct testing.
 */
function adapterProject(AgentResponse $response): AgentStepResult
{
    return (new class extends AgentAdapter
    {
        public function project(AgentResponse $response): AgentStepResult
        {
            return $this->toResult($response);
        }
    })->project($response);
}

function toolLoopResponse(): AgentResponse
{
    return (new AgentResponse('inv-1', 'The number is 72019.', new Usage(30, 12), new Meta('anthropic', 'claude-sonnet-5')))
        ->withSteps(collect([
            new Step(
                text: '',
                toolCalls: [new ToolCall('call-1', 'number_generator', ['digits' => 5])],
                toolResults: [new ToolResult('call-1', 'number_generator', ['digits' => 5], ['number' => 72019])],
                finishReason: FinishReason::ToolCalls,
                usage: new Usage(20, 4),
                meta: new Meta('anthropic', 'claude-sonnet-5'),
            ),
            new Step(
                text: 'The number is 72019.',
                toolCalls: [],
                toolResults: [],
                finishReason: FinishReason::Stop,
                usage: new Usage(10, 8),
                meta: new Meta('anthropic', 'claude-sonnet-5'),
            ),
        ]));
}

it('projects each provider call with its invocation id, origin, and usage', function () {
    $result = adapterProject(toolLoopResponse());

    expect($result->invocationId)->toBe('inv-1')
        ->and($result->calls)->toHaveCount(2)
        ->and($result->calls[0])->toMatchArray([
            'invocation_id' => 'inv-1',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'finish_reason' => 'tool_calls',
        ])
        ->and($result->calls[0]['usage']['prompt_tokens'])->toBe(20)
        ->and($result->calls[1]['finish_reason'])->toBe('stop')
        ->and($result->calls[1])->not->toHaveKey('tool_calls');
});

it('records tool arguments and results under the full audit mode', function () {
    config()->set('agent-workflows.audit.step_calls', 'full');

    $calls = adapterProject(toolLoopResponse())->calls;

    expect($calls[0]['tool_calls'][0])->toBe(['id' => 'call-1', 'name' => 'number_generator', 'arguments' => ['digits' => 5]])
        ->and($calls[0]['tool_results'][0])->toBe(['id' => 'call-1', 'name' => 'number_generator', 'result' => ['number' => 72019]]);
});

it('keeps only tool ids and names under the minimal audit mode', function () {
    config()->set('agent-workflows.audit.step_calls', 'minimal');

    $calls = adapterProject(toolLoopResponse())->calls;

    expect($calls[0]['tool_calls'][0])->toBe(['id' => 'call-1', 'name' => 'number_generator'])
        ->and($calls[0]['tool_results'][0])->toBe(['id' => 'call-1', 'name' => 'number_generator']);
});

it('projects an empty audit for hand-built responses that never ran the loop', function () {
    $response = AgentResponse::fakeWithPendingApprovals([]);

    expect(adapterProject($response)->calls)->toBe([]);
});

it('concatenates call audits across results in call order', function () {
    $first = adapterProject(toolLoopResponse());

    $second = adapterProject(
        (new AgentResponse('inv-2', 'Verdict.', new Usage(5, 2), new Meta('openai', 'gpt-5')))
            ->withSteps(collect([
                new Step('Verdict.', [], [], FinishReason::Stop, new Usage(5, 2), new Meta('openai', 'gpt-5')),
            ])),
    );

    $merged = AgentStepResult::calls($first, $second);

    expect($merged)->toHaveCount(3)
        ->and($merged[2]['invocation_id'])->toBe('inv-2')
        ->and(AgentStepResult::calls())->toBe([]);
});
