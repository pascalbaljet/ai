<?php

namespace Laravel\Ai\Contracts;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Laravel\Ai\Storage\StoredMessage;

interface PaginatesConversations
{
    /**
     * Page back through a conversation's stored messages, newest first.
     *
     * Items must expose the ordering column, `id`, since the paginator builds
     * the next cursor from the item it returns.
     *
     * @return CursorPaginator<int, StoredMessage>
     */
    public function paginateConversationMessages(
        string $conversationId,
        int $perPage = 15,
        string $cursorName = 'cursor',
        Cursor|string|null $cursor = null,
    ): CursorPaginator;
}
