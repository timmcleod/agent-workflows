<?php

namespace TimMcLeod\AgentWorkflows\Enums;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case AwaitingHuman = 'awaiting_human';
    case AwaitingEvent = 'awaiting_event';
    case Failed = 'failed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled]);
    }

    public function isInterrupted(): bool
    {
        return in_array($this, [self::AwaitingHuman, self::AwaitingEvent]);
    }
}
