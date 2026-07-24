<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Responses\Data\ToolCall;
use TimMcLeod\AgentWorkflows\Events\ConversationTransferred;
use TimMcLeod\AgentWorkflows\Exceptions\WorkflowException;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Handoffs\HandoffTool;
use TimMcLeod\AgentWorkflows\Models\ConversationOwner;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\RefundsAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Agents\TriageAgent;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\TestUser;

it('generates a synthetic transfer tool per handoff target', function () {
    $tools = (new TriageAgent)->handoffTools();

    expect($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(HandoffTool::class)
        ->and($tools[0]->name())->toBe('transfer_to_refunds_agent')
        // The target customizes the tool description via handoffDescription().
        ->and((string) $tools[0]->description())->toBe('Transfer to the refunds specialist for anything refund-related.');
});

it('records conversation ownership when the agent calls a transfer tool', function () {
    TriageAgent::fake([
        new ToolCall('call-1', 'transfer_to_refunds_agent', ['reason' => 'Customer wants a refund']),
        'Transferring you to our refunds specialist.',
    ]);

    $user = TestUser::create(['name' => 'Tim']);

    $response = (new TriageAgent)->forUser($user)->prompt('I want a refund for order #42');

    expect($response->conversationId)->not->toBeNull();

    $owner = ConversationOwner::sole();

    expect($owner->conversation_id)->toBe($response->conversationId)
        ->and($owner->agent)->toBe(RefundsAgent::class);
});

it('routes the next turn to the conversation owner', function () {
    TriageAgent::fake([
        new ToolCall('call-1', 'transfer_to_refunds_agent', []),
        'Transferring you now.',
    ]);
    RefundsAgent::fake(['I can help with that refund.']);

    $user = TestUser::create(['name' => 'Tim']);

    $first = (new TriageAgent)->forUser($user)->prompt('I want a refund');

    // The next user message arrives — route it to whoever owns the conversation.
    $agent = AgentWorkflow::resolveAgentFor($first->conversationId, default: TriageAgent::class);

    expect($agent)->toBeInstanceOf(RefundsAgent::class);

    $second = $agent->continue($first->conversationId, $user)->prompt('Order #42, please');

    expect($second->text)->toBe('I can help with that refund.')
        ->and($second->conversationId)->toBe($first->conversationId);
});

it('resolves the default agent for an unowned conversation and throws without one', function () {
    $agent = AgentWorkflow::resolveAgentFor('01980000-0000-7000-8000-000000000000', default: TriageAgent::class);

    expect($agent)->toBeInstanceOf(TriageAgent::class);

    expect(fn () => AgentWorkflow::resolveAgentFor('01980000-0000-7000-8000-000000000000'))
        ->toThrow(WorkflowException::class);
});

it('transfers ownership manually and fires ConversationTransferred', function () {
    Event::fake([ConversationTransferred::class]);

    AgentWorkflow::transferConversation('conv-1', RefundsAgent::class);

    expect(AgentWorkflow::resolveAgentFor('conv-1'))->toBeInstanceOf(RefundsAgent::class);

    // A second transfer records the previous owner on the event.
    AgentWorkflow::transferConversation('conv-1', TriageAgent::class);

    Event::assertDispatched(ConversationTransferred::class, function (ConversationTransferred $e) {
        return $e->conversationId === 'conv-1'
            && $e->agent === TriageAgent::class
            && $e->previous === RefundsAgent::class;
    });

    expect(ConversationOwner::sole()->agent)->toBe(TriageAgent::class);
});
