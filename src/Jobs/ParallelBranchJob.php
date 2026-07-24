<?php

namespace TimMcLeod\AgentWorkflows\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use TimMcLeod\AgentWorkflows\Runtime\BranchRunner;

/**
 * One branch of a durable ("batch" mode) parallel step. The batch's
 * callbacks — not this job — advance the workflow once all branches finish.
 */
class ParallelBranchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public string $runId,
        public string $parallelStepId,
        public string $branchId,
    ) {
        $this->onConnection(config('agent-workflows.queue.connection'));
        $this->onQueue(config('agent-workflows.queue.queue'));
    }

    public function handle(BranchRunner $runner): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $runner->run($this->runId, $this->parallelStepId, $this->branchId);
    }
}
