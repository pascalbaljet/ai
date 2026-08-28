<?php

namespace Laravel\Ai\Exceptions;

class ConversationOwnershipException extends AiException
{
    /**
     * Create a new conversation ownership exception.
     */
    public function __construct(
        public string $conversationId,
        string $message = 'The conversation does not belong to the given participant.',
    ) {
        parent::__construct($message);
    }
}
