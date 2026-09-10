<?php

namespace Laravel\Ai\Chat;

use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Models\Conversation;

/**
 * Does this conversation belong to this participant?
 */
class ConversationOwnership
{
    public static function belongsTo(?string $conversationId, ?object $participant): bool
    {
        $store = resolve(ConversationStore::class);

        if ($conversationId === null || $participant === null || ! $store instanceof VerifiesConversationOwnership) {
            return false;
        }

        return $store->conversationBelongsTo(
            $conversationId,
            Conversation::participantType($participant),
            Conversation::participantKey($participant),
        );
    }
}
