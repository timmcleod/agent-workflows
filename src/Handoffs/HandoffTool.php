<?php

namespace TimMcLeod\AgentWorkflows\Handoffs;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Synthetic tool exposed to a HasHandoffs agent for each transfer target.
 * The tool itself only acknowledges the transfer — ownership is recorded by
 * the RecordHandoffs listener when the response carries this tool call.
 */
class HandoffTool implements Tool
{
    /**
     * @param  class-string<Agent>  $target
     */
    public function __construct(public readonly string $target) {}

    /**
     * @param  class-string<Agent>  $target
     */
    public static function nameFor(string $target): string
    {
        return 'transfer_to_'.Str::snake(class_basename($target));
    }

    public function name(): string
    {
        return static::nameFor($this->target);
    }

    public function description(): Stringable|string
    {
        if (method_exists($this->target, 'handoffDescription')) {
            return app($this->target)->handoffDescription();
        }

        return sprintf(
            'Transfer this conversation to %s. Use this when the conversation should be handled by that agent instead of you.',
            class_basename($this->target),
        );
    }

    public function handle(Request $request): Stringable|string
    {
        return sprintf(
            'The conversation has been transferred to %s. Let the user know briefly; they will continue there.',
            class_basename($this->target),
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reason' => $schema->string()->description('Why the conversation is being transferred.'),
        ];
    }
}
