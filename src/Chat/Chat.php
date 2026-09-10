<?php

namespace Laravel\Ai\Chat;

use Laravel\Ai\AgentUserInteraction\AgentUserInteraction;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Contracts\RemembersConversations;

/**
 * The two halves of a chat: what a page loads with, and what a turn streams.
 */
class Chat
{
    /**
     * Everything a browser needs to start talking to the agent.
     */
    public static function make(RemembersConversations $agent): ChatState
    {
        return ChatState::make($agent);
    }

    /**
     * One turn, streamed as AG-UI.
     *
     * The input is read from the request unless one is handed over, since a turn arrives as a
     * RunAgentInput and nothing narrower carries the thread and run the client is on...
     */
    public static function stream(
        Agent&RemembersConversations $agent,
        ?AgentInput $input = null,
    ): AgentStream {
        return new AgentStream($agent, $input ?? AgentUserInteraction::chat(request()));
    }
}
