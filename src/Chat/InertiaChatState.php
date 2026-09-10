<?php

namespace Laravel\Ai\Chat;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Inertia\Inertia;
use Inertia\PropertyContext;
use Inertia\ProvidesInertiaProperty;

/**
 * `ChatState` for a page that is an Inertia page.
 */
class InertiaChatState extends ChatState implements ProvidesInertiaProperty
{
    /**
     * @return array<string, mixed>
     */
    public function toInertiaProperty(PropertyContext $prop): array
    {
        return $this->payload(
            $prop->request,
            Inertia::scroll(fn (): CursorPaginator => $this->latestMessages()),
            "{$prop->key}.messages",
        );
    }
}
