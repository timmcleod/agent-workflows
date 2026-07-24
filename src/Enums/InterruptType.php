<?php

namespace TimMcLeod\AgentWorkflows\Enums;

enum InterruptType: string
{
    case Human = 'human';
    case Approval = 'approval';
    case Event = 'event';

    public function runStatus(): RunStatus
    {
        return $this === self::Event ? RunStatus::AwaitingEvent : RunStatus::AwaitingHuman;
    }
}
