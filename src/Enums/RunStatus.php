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

    /**
     * Whether the run can still do or receive work on its own. Failed is
     * not active (it advances only through an explicit retry()) yet not
     * terminal either (that retry exists).
     */
    public function isActive(): bool
    {
        return ! in_array($this, [self::Failed, self::Completed, self::Cancelled]);
    }

    public function isInterrupted(): bool
    {
        return in_array($this, [self::AwaitingHuman, self::AwaitingEvent]);
    }
}
