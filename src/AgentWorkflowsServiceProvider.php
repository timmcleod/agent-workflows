<?php

namespace TimMcLeod\AgentWorkflows;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use TimMcLeod\AgentWorkflows\Console\MakeAgentWorkflowCommand;
use TimMcLeod\AgentWorkflows\Console\SweepCommand;
use TimMcLeod\AgentWorkflows\Handoffs\HandoffManager;
use TimMcLeod\AgentWorkflows\Listeners\RecordHandoffs;

class AgentWorkflowsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-workflows.php', 'agent-workflows');

        $this->app->singleton(WorkflowRegistry::class);
        $this->app->singleton(WorkflowManager::class);
        $this->app->singleton(HandoffManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Event::listen(AgentPrompted::class, RecordHandoffs::class);
        Event::listen(AgentStreamed::class, RecordHandoffs::class);

        $this->registerConfiguredWorkflows();

        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeAgentWorkflowCommand::class,
                SweepCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/agent-workflows.php' => config_path('agent-workflows.php'),
            ], 'agent-workflows-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'agent-workflows-migrations');
        }
    }

    /**
     * Class-based workflows listed in the "workflows" config array are
     * registered at boot on every process — including queue workers, which
     * must know the definitions to execute steps.
     */
    protected function registerConfiguredWorkflows(): void
    {
        $manager = $this->app->make(WorkflowManager::class);

        foreach (config('agent-workflows.workflows', []) as $workflow) {
            $manager->register($workflow);
        }
    }
}
