<?php

namespace Laravel\Ai\Chat;

use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;

/**
 * What a paused turn is still waiting on.
 */
class PausedApprovals
{
    /**
     * The approvals the conversation's newest turn is paused on.
     *
     * @return list<PendingApproval>
     */
    public static function forConversation(?string $conversationId): array
    {
        $store = resolve(ConversationStore::class);

        if ($conversationId === null || ! $store instanceof ResolvesPendingApprovals) {
            return [];
        }

        return $store->pendingApprovalsFor($conversationId);
    }
}
