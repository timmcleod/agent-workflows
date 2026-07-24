<?php

use TimMcLeod\AgentWorkflows\Enums\RunStatus;
use TimMcLeod\AgentWorkflows\Facades\AgentWorkflow;
use TimMcLeod\AgentWorkflows\Tests\Fixtures\Workflows\ContractReviewWorkflow;
use TimMcLeod\AgentWorkflows\WorkflowRegistry;

it('registers a class-based workflow under its kebab-cased name', function () {
    $definition = AgentWorkflow::register(ContractReviewWorkflow::class);

    expect($definition->name)->toBe('contract-review-workflow')
        ->and(app(WorkflowRegistry::class)->has('contract-review-workflow'))->toBeTrue();
});

it('starts a class-based workflow by its class name', function () {
    $run = AgentWorkflow::start(ContractReviewWorkflow::class, []);

    expect($run->name)->toBe('contract-review-workflow')
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and($run->state['finalized'])->toBeTrue();
});

it('generates a workflow class with the artisan command', function () {
    $path = app_path('AgentWorkflows/ContractReview.php');

    if (file_exists($path)) {
        unlink($path);
    }

    $this->artisan('make:agent-workflow', ['name' => 'ContractReview'])->assertSuccessful();

    expect(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toContain('class ContractReview extends Workflow');

    unlink($path);
});
