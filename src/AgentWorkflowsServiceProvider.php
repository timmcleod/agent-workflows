<?php

namespace TimMcLeod\AgentWorkflows;

use Illuminate\Support\ServiceProvider;
use TimMcLeod\AgentWorkflows\Console\MakeAgentWorkflowCommand;
use TimMcLeod\AgentWorkflows\Console\SweepCommand;

class AgentWorkflowsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-workflows.php', 'agent-workflows');

        $this->app->singleton(WorkflowRegistry::class);
        $this->app->singleton(WorkflowManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

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
