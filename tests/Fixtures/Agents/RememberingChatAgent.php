<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationHistory;
use Laravel\Ai\Promptable;

/**
 * An agent a chat route may take, which is the trait and the contract together.
 */
class RememberingChatAgent implements Agent, RemembersConversationHistory
{
    use Promptable, RemembersConversations;

    public function instructions(): string
    {
        return 'You are a helpful assistant that responds extremely concisely to all queries.';
    }
}
