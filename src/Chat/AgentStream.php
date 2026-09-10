<?php

namespace Laravel\Ai\Chat;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\AgentUserInteraction\Chat as AgentUserInteractionChat;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Streaming\Protocols\AgentUserInteractionProtocol;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * One turn, streamed to a chat client as AG-UI.
 */
class AgentStream extends AgentUserInteractionProtocol implements Responsable
{
    public function __construct(
        protected Agent&RemembersConversations $agent,
        protected AgentInput $prompt,
    ) {
        // The client's own thread and run, so RUN_STARTED echoes what it sent rather than
        // identifiers this side invented. Absent ones fall back to the conversation...
        parent::__construct($this->promptThreadId(), $this->promptRunId());

        $this->assertConversationIsScoped();

        if ($this->agent->currentConversation() !== null) {
            $this->assertDecisionsMatchThePause();
        }
    }

    /**
     * Continue the thread the turn names, as the given participant.
     */
    public function as(?object $participant): static
    {
        if ($participant !== null) {
            $this->agent->continueOrStart($this->threadIdForScoping(), as: $participant);
        }

        $this->assertConversationIsScoped();
        $this->assertDecisionsMatchThePause();

        return $this;
    }

    public function toResponse($request): Response
    {
        $this->assertPromptThreadIsScoped();
        $this->assertConversationIsScoped();
        $this->assertDecisionsMatchThePause();

        if ($request->hasSession()) {
            $request->session()->save();
        }

        return $this->response($this->agent->stream($this->prompt));
    }

    /**
     * A conversation may only be continued by whoever the route said owns it.
     */
    protected function assertConversationIsScoped(): void
    {
        $conversationId = $this->agent->currentConversation();

        if ($conversationId === null) {
            return;
        }

        $participant = $this->agent->conversationParticipant();

        if ($participant === null) {
            throw new RuntimeException(
                'An agent continuing a conversation must be scoped to a participant: '
                .'call continue($id, as: $user) before prompting it.',
            );
        }

        if (! ConversationOwnership::belongsTo($conversationId, $participant)) {
            throw ValidationException::withMessages([
                'threadId' => 'The conversation does not belong to the authenticated user.',
            ]);
        }
    }

    protected function assertPromptThreadIsScoped(): void
    {
        $threadId = $this->promptThreadId();

        if ($threadId === null || $this->agent->currentConversation() === $threadId) {
            return;
        }

        throw new RuntimeException(
            'A chat stream continuing a conversation must be scoped to a participant: '
            .'call as($user), or continue($id, as: $user) on the agent.',
        );
    }

    protected function threadIdForScoping(): ?string
    {
        return $this->promptThreadId() ?? $this->agent->currentConversation();
    }

    protected function promptThreadId(): ?string
    {
        if (! $this->prompt instanceof AgentUserInteractionChat) {
            return null;
        }

        $threadId = $this->prompt->threadId();

        return filled($threadId) ? $threadId : null;
    }

    protected function promptRunId(): ?string
    {
        if (! $this->prompt instanceof AgentUserInteractionChat) {
            return null;
        }

        $runId = $this->prompt->runId();

        return filled($runId) ? $runId : null;
    }

    /**
     * A resume may only decide the calls the run actually paused on.
     *
     * @throws ApprovalMismatchException
     */
    protected function assertDecisionsMatchThePause(): void
    {
        $decisions = $this->prompt->decisions();

        if ($decisions === null) {
            return;
        }

        $pending = PausedApprovals::forConversation($this->agent->currentConversation());

        if ($pending === []) {
            throw new ApprovalMismatchException(
                'There are no tool calls pending approval.',
                collect(),
            );
        }

        $pendingIds = array_map(fn (PendingApproval $approval): string => $approval->id, $pending);

        // `*` is the SDK's "decide everything still outstanding" wildcard, so it
        // matches whatever is pending by definition.
        $unknown = collect(array_keys($decisions->all()))
            ->reject(fn (string $id): bool => $id === '*')
            ->reject(fn (string $id): bool => in_array($id, $pendingIds, true));

        if ($unknown->isNotEmpty()) {
            throw new ApprovalMismatchException(
                'Approval decisions do not match the pending tool calls.',
                collect($pending),
            );
        }
    }
}
