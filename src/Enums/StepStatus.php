<?php

namespace TimMcLeod\AgentWorkflows\Enums;

enum StepStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Interrupted = 'interrupted';
}
