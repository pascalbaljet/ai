<?php

namespace Laravel\Ai\Chat;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Validation\ValidationException;
use Inertia\ProvidesInertiaProperty;
use JsonSerializable;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\PaginatesConversations;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Storage\StoredMessage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything a browser needs to start talking to an agent.
 */
class ChatState implements Arrayable, Jsonable, JsonSerializable, Responsable
{
    /**
     * Decorates the default message shape.
     *
     * @var (callable(array<string, mixed>, StoredMessage): array<string, mixed>)|null
     */
    protected $mapper = null;

    protected ?string $endpoint = null;

    protected int $perPage = 20;

    protected bool $attachments = false;

    protected ?object $participant = null;

    /**
     * Resolved once per render, since every row's approvals are a slice of the same answer.
     *
     * @var list<PendingApproval>|null
     */
    protected ?array $pausedOn = null;

    public function __construct(protected RemembersConversations $agent) {}

    /**
     * Build the state for the given agent.
     */
    public static function make(RemembersConversations $agent): self
    {
        return interface_exists(ProvidesInertiaProperty::class)
            ? new InertiaChatState($agent)
            : new self($agent);
    }

    /**
     * Whose conversation this is, when the agent was not already scoped to them.
     */
    public function as(?object $participant): static
    {
        $this->participant = $participant;

        $this->assertConversationIsOwned();

        return $this;
    }

    /**
     * A conversation may only be read by whoever owns it.
     *
     * @throws ValidationException
     */
    protected function assertConversationIsOwned(): void
    {
        $conversationId = $this->agent->currentConversation();

        if ($conversationId === null) {
            return;
        }

        $participant = $this->participant ?? $this->agent->conversationParticipant();

        if ($participant === null) {
            throw new RuntimeException(
                'A chat continuing a conversation must be scoped to a participant: '
                .'call as($user), or continue($id, as: $user) on the agent.',
            );
        }

        if (! ConversationOwnership::belongsTo($conversationId, $participant)) {
            throw ValidationException::withMessages([
                'threadId' => 'The conversation does not belong to the authenticated user.',
            ]);
        }
    }

    /**
     * Where a turn is POSTed.
     */
    public function endpoint(string $endpoint): static
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    /**
     * Add to the default message shape.
     *
     * @param  callable(array<string, mixed>, StoredMessage): array<string, mixed>  $callback
     */
    public function mapMessages(callable $callback): static
    {
        $this->mapper = $callback;

        return $this;
    }

    /**
     * Whether this viewer can attach files to a turn.
     */
    public function withAttachments(bool $supported = true): static
    {
        $this->attachments = $supported;

        return $this;
    }

    /**
     * Page the transcript instead of sending all of it.
     */
    public function paginate(int $perPage = 30): static
    {
        $this->perPage = $perPage;

        return $this;
    }

    public function toResponse($request): Response
    {
        return response()->json($this->for($request));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->for(request());
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * The payload, for a caller that is not an Inertia page.
     *
     * @return array<string, mixed>
     */
    public function for(Request $request): array
    {
        return $this->payload($request, $this->latestMessages()->toArray());
    }

    /**
     * @param  list<array<string, mixed>>|CursorPaginator<int, array<string, mixed>>|array<string, mixed>|mixed  $messages
     * @return array<string, mixed>
     */
    protected function payload(
        Request $request,
        mixed $messages,
        ?string $transcriptPath = null,
    ): array {
        // Every way of reading the payload funnels through here, so the check
        // cannot be skipped by reaching for a different method...
        $this->assertConversationIsOwned();

        $pendingApprovals = $this->pendingApprovals();

        return [
            'conversationId' => $this->agent->currentConversation(),
            'endpoint' => $this->endpoint ?? $request->url(),
            'messages' => $messages,
            'transcriptPath' => $transcriptPath,
            // Only two of the client's states are knowable server-side;
            // `streaming` belongs to the browser holding the connection.
            'status' => $pendingApprovals === [] ? 'idle' : 'awaiting_approval',
            'pendingApprovals' => $pendingApprovals,
            'capabilities' => $this->capabilities(),
        ];
    }

    /**
     * The approvals the conversation is paused on.
     *
     * @return list<array<string, mixed>>
     */
    protected function pendingApprovals(): array
    {
        return array_map(fn (PendingApproval $approval): array => $approval->toArray(), $this->pausedOn());
    }

    /**
     * The approvals the conversation's newest turn is paused on, resolved once for the whole render.
     *
     * @return list<PendingApproval>
     */
    protected function pausedOn(): array
    {
        return $this->pausedOn ??= PausedApprovals::forConversation($this->agent->currentConversation());
    }

    /**
     * The approvals of the given row, which are its share of the ones the conversation is paused on.
     *
     * @return list<PendingApproval>
     */
    protected function pausedOnFor(StoredMessage $message): array
    {
        $callIds = collect($message->toolCalls)->pluck('id');

        return collect($this->pausedOn())
            ->filter(fn (PendingApproval $approval): bool => $callIds->contains($approval->id))
            ->values()
            ->all();
    }

    /**
     * What the client may render, read off the agent's own interfaces.
     *
     * @return array<string, bool>
     */
    protected function capabilities(): array
    {
        $tools = $this->agent instanceof HasTools ? collect($this->agent->tools()) : collect();

        return array_filter([
            'streaming' => ! $this->agent instanceof HasStructuredOutput,
            'approvals' => $tools->contains(fn (object $tool): bool => $tool instanceof Approvable),
            // A provider fact, supplied by the route. See `withAttachments()`.
            'attachments' => $this->attachments,
        ]);
    }

    /**
     * The newest page of the transcript, newest message first.
     *
     * @return CursorPaginator<int, array<string, mixed>>
     */
    protected function latestMessages(): CursorPaginator
    {
        $store = resolve(ConversationStore::class);
        $conversationId = $this->agent->currentConversation();

        if ($conversationId === null || ! $store instanceof PaginatesConversations) {
            return new CursorPaginator([], $this->perPage);
        }

        return $store->paginateConversationMessages($conversationId, $this->perPage)
            ->through(fn (StoredMessage $message): array => $this->message($message));
    }

    /**
     * @return array<string, mixed>
     */
    protected function message(StoredMessage $message): array
    {
        $default = [
            'id' => $message->id,
            'role' => $message->role,
            'content' => (string) $message->content,
            // Persisted since laravel/ai#975, so a reloaded turn shows what a
            // live one did. Empty on a model that did not reason...
            'reasoning' => $this->reasoning($message),
            'status' => $this->pendingApprovalIds($message) === [] ? 'complete' : 'awaiting_approval',
            'toolCalls' => $this->toolCalls($message),
            'approvals' => $this->approvals($message),
            'citations' => $this->citations($message),
            'usage' => $message->usage ?: null,
            'error' => null,
            'provider' => $message->meta['provider'] ?? null,
            'model' => $message->meta['model'] ?? null,
            'createdAt' => $message->createdAt?->toIso8601String(),
            'parts' => $this->parts($message),
        ];

        if ($this->mapper !== null) {
            $default = call_user_func($this->mapper, $default, $message);
        }

        return $default;
    }

    /**
     * The stored row as ordered parts, mirroring what the reducer builds from a stream — `content`, `toolCalls` and `citations` are projections of these.
     *
     * @return list<array<string, mixed>>
     */
    protected function parts(StoredMessage $message): array
    {
        $text = filled($message->content)
            ? [['type' => 'text', 'text' => (string) $message->content]]
            : [];

        $files = array_map(
            fn (array $attachment): array => $this->filePart($attachment),
            is_array($message->attachments) ? $message->attachments : [],
        );

        $tools = array_map(
            fn (array $call): array => [
                'type' => 'dynamic-tool',
                'toolName' => $call['name'],
                'toolCallId' => $call['id'],
                'state' => $call['state'],
                'input' => $call['arguments'],
                'output' => $call['result'],
                'errorText' => $call['error'],
            ],
            $this->toolCalls($message),
        );

        $sources = array_map(
            fn (array $citation): array => [
                'type' => 'source-url',
                'sourceId' => (string) $citation['url'],
                'url' => (string) $citation['url'],
                'title' => $citation['title'] ?? null,
            ],
            $this->citedSources($message),
        );

        $reasoning = filled($thought = $this->reasoning($message))
            ? [['type' => 'reasoning', 'text' => $thought]]
            : [];

        // Attachments first: a person attaches, then says what to do with it.
        // Reasoning precedes the answer, because that is the order it happened.
        return [...$files, ...$reasoning, ...$text, ...$tools, ...$sources];
    }

    /**
     * What the model thought before answering, persisted since laravel/ai#975.
     */
    protected function reasoning(StoredMessage $message): string
    {
        $reasoning = $message->meta['reasoning'] ?? '';

        return is_string($reasoning) ? $reasoning : '';
    }

    /**
     * The citations the turn produced, as the reducer shapes them live.
     *
     * @return list<array<string, mixed>>
     */
    protected function citations(StoredMessage $message): array
    {
        return collect($this->citedSources($message))
            ->unique('url')
            ->values()
            ->all();
    }

    /**
     * Every source the turn cited, in the order it cited them.
     *
     * @return list<array<string, mixed>>
     */
    protected function citedSources(StoredMessage $message): array
    {
        $citations = $message->meta['citations'] ?? [];

        return collect(is_array($citations) ? $citations : [])
            ->filter(fn ($citation): bool => is_array($citation) && filled($citation['url'] ?? null))
            ->map(fn (array $citation): array => [
                'title' => $citation['title'] ?? null,
                'url' => (string) $citation['url'],
            ])
            ->values()
            ->all();
    }

    /**
     * One stored attachment, as a `file` part.
     *
     * @param  array<string, mixed>  $attachment
     * @return array<string, mixed>
     */
    protected function filePart(array $attachment): array
    {
        // The stored path never leaves the server, so the part carries no URL. An application
        // that serves its attachments attaches an authorized one through mapMessages()...
        return [
            'type' => 'file',
            'filename' => $attachment['name'] ?? basename((string) ($attachment['path'] ?? '')),
            'mediaType' => $attachment['mime'] ?? null,
            'url' => null,
        ];
    }

    /**
     * Stitch each stored call back together with its result, mirroring what the client reducer does with `tool_call` and `tool_result` events.
     *
     * @return list<array<string, mixed>>
     */
    protected function toolCalls(StoredMessage $message): array
    {
        $results = collect($message->toolResults ?? [])->keyBy('id');
        $pending = $this->pendingApprovalIds($message);

        return collect($message->toolCalls ?? [])
            ->map(function (array $call) use ($results, $pending): array {
                $result = $results->get($call['id'] ?? '');

                return [
                    'id' => $call['id'] ?? '',
                    'name' => $call['name'] ?? '',
                    'arguments' => $call['arguments'] ?? [],
                    'result' => $this->stringifyResult($result['result'] ?? null),
                    'state' => match (true) {
                        in_array($call['id'] ?? '', $pending, true) => 'awaiting_approval',
                        $result !== null => 'complete',
                        default => 'running',
                    },
                    'error' => null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function approvals(StoredMessage $message): array
    {
        return array_map(fn (PendingApproval $approval): array => $approval->toArray(), $this->pausedOnFor($message));
    }

    /**
     * @return list<string>
     */
    protected function pendingApprovalIds(StoredMessage $message): array
    {
        return array_map(fn (PendingApproval $approval): string => $approval->id, $this->pausedOnFor($message));
    }

    protected function stringifyResult(mixed $result): ?string
    {
        if ($result === null) {
            return null;
        }

        return is_string($result)
            ? $result
            : (string) json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
