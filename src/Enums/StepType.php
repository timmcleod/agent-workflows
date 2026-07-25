<?php

namespace TimMcLeod\AgentWorkflows\Enums;

enum StepType: string
{
    case Agent = 'agent';
    case Callback = 'callback';
    case Condition = 'condition';
    case Parallel = 'parallel';
    case Evaluate = 'evaluate';
    case AwaitHuman = 'await_human';
    case AwaitEvent = 'await_event';
}
