<?php

namespace Tests\Fixtures;

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Models\Conversation;

/**
 * A store that answers the questions a chat route asks of it.
 *
 * The base fake implements only ConversationStore, and the chat surface degrades to a refusal
 * against a store that cannot answer — which is worth exercising separately from the happy path.
 */
class FakeChatConversationStore extends FakeConversationStore implements ResolvesPendingApprovals, VerifiesConversationOwnership
{
    /**
     * @param  list<PendingApproval>  $pending
     */
    public function __construct(
        public array $pending = [],
        public ?object $owner = null,
    ) {}

    public function conversationBelongsTo(string $conversationId, ?string $participantType, string|int|null $participantId): bool
    {
        return $this->owner !== null
            && Conversation::participantType($this->owner) === $participantType
            && (string) Conversation::participantKey($this->owner) === (string) $participantId;
    }

    /**
     * @return list<PendingApproval>
     */
    public function pendingApprovalsFor(string $conversationId): array
    {
        return $this->pending;
    }
}
