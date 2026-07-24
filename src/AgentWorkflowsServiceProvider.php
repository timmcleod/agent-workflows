<?php

namespace TimMcLeod\AgentWorkflows;

use Illuminate\Support\ServiceProvider;

class AgentWorkflowsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agent-workflows.php', 'agent-workflows');

        $this->app->singleton(WorkflowRegistry::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/agent-workflows.php' => config_path('agent-workflows.php'),
            ], 'agent-workflows-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'agent-workflows-migrations');
        }
    }
}
