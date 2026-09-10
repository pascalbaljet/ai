<?php

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\AgentUserInteraction\AgentUserInteraction;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Chat\Chat;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\Agents\RememberingChatAgent;
use Tests\Fixtures\FakeChatConversationStore;
use Tests\Fixtures\FakeConversationStore;

function chatUser(int $id = 1): object
{
    return new class($id)
    {
        public function __construct(public int $id) {}
    };
}

/**
 * A RunAgentInput as the client posts one.
 *
 * `conversation-123` is what the fake store hands back from storeConversation(), so naming it
 * here is what makes a turn continue an existing thread rather than open a new one.
 */
function chatInput(array $overrides = []): array
{
    return array_replace([
        'threadId' => 'conversation-123',
        'runId' => 'run-from-the-client',
        'messages' => [['id' => 'm1', 'role' => 'user', 'content' => 'Hello']],
    ], $overrides);
}

function chatEvents(Response $response): array
{
    $output = '';

    ob_start(function (string $buffer) use (&$output): string {
        $output .= $buffer;

        return '';
    });

    $response->sendContent();

    ob_end_clean();

    if (($frames = trim($output)) === '') {
        return [];
    }

    return collect(explode("\n\n", $frames))
        ->map(fn (string $frame) => json_decode(str_replace('data: ', '', $frame), true))
        ->all();
}

test('a turn reads its input from the request', function () {
    $user = chatUser();

    app()->instance(ConversationStore::class, new FakeChatConversationStore(owner: $user));
    app()->instance('request', Request::create('/chat', 'POST', chatInput()));

    RememberingChatAgent::fake(['Fake response']);

    $events = chatEvents(Chat::stream(new RememberingChatAgent)->as($user)->toResponse(request()));

    expect($events[0]['type'])->toBe('RUN_STARTED')
        ->and($events[0]['threadId'])->toBe('conversation-123');
});

test('the run id the client sent is echoed rather than replaced', function () {
    $user = chatUser();

    app()->instance(ConversationStore::class, new FakeChatConversationStore(owner: $user));

    RememberingChatAgent::fake(['Fake response']);

    $input = AgentUserInteraction::chat(chatInput(['runId' => 'run-from-the-client']));

    $events = chatEvents(Chat::stream(new RememberingChatAgent, $input)->as($user)->toResponse(request()));

    expect($events[0]['runId'])->toBe('run-from-the-client')
        ->and(end($events)['runId'])->toBe('run-from-the-client');
});

test('a run the client did not name falls back to the invocation', function () {
    $user = chatUser();

    app()->instance(ConversationStore::class, new FakeChatConversationStore(owner: $user));

    RememberingChatAgent::fake(['Fake response']);

    $input = AgentUserInteraction::chat(chatInput(['runId' => '']));

    $events = chatEvents(Chat::stream(new RememberingChatAgent, $input)->as($user)->toResponse(request()));

    expect($events[0]['runId'])->not->toBeEmpty()
        ->and($events[0]['runId'])->not->toBe('run-from-the-client');
});

test('a first turn reports the thread the conversation was stored under', function () {
    $user = chatUser();

    app()->instance(ConversationStore::class, new FakeChatConversationStore(owner: $user));

    RememberingChatAgent::fake(['Fake response']);

    $agent = new RememberingChatAgent;

    $input = AgentUserInteraction::chat(chatInput(['threadId' => '']));

    $events = chatEvents(Chat::stream($agent, $input)->as($user)->toResponse(request()));

    expect($agent->currentConversation())->not->toBeNull()
        ->and($events[0]['threadId'])->toBe($agent->currentConversation());
});

test('a conversation belonging to another participant is refused', function () {
    app()->instance(ConversationStore::class, new FakeChatConversationStore(owner: chatUser(999)));

    RememberingChatAgent::fake(['Fake response']);

    Chat::stream(new RememberingChatAgent, AgentUserInteraction::chat(chatInput()))->as(chatUser());
})->throws(ValidationException::class, 'The conversation does not belong to the authenticated user.');

test('a resume is refused when the run is not paused on anything', function () {
    $user = chatUser();

    app()->instance(ConversationStore::class, new FakeChatConversationStore(owner: $user));

    RememberingChatAgent::fake(['Fake response']);

    $input = AgentUserInteraction::chat(chatInput([
        'resume' => [['interruptId' => 'call-1', 'status' => 'resolved', 'payload' => ['approved' => true]]],
    ]));

    Chat::stream(new RememberingChatAgent, $input)->as($user);
})->throws(ApprovalMismatchException::class, 'There are no tool calls pending approval.');

test('a resume is refused when it decides a call the run is not waiting on', function () {
    $user = chatUser();

    app()->instance(ConversationStore::class, new FakeChatConversationStore(
        pending: [new PendingApproval('call-1', 'SaveNote', ['file' => 'a.txt'])],
        owner: $user,
    ));

    RememberingChatAgent::fake(['Fake response']);

    $input = AgentUserInteraction::chat(chatInput([
        'resume' => [['interruptId' => 'call-2', 'status' => 'resolved', 'payload' => ['approved' => true]]],
    ]));

    Chat::stream(new RememberingChatAgent, $input)->as($user);
})->throws(ApprovalMismatchException::class, 'Approval decisions do not match the pending tool calls.');

test('a resume that decides the pending call is allowed through', function () {
    $user = chatUser();

    app()->instance(ConversationStore::class, new FakeChatConversationStore(
        pending: [new PendingApproval('call-1', 'SaveNote', ['file' => 'a.txt'])],
        owner: $user,
    ));

    RememberingChatAgent::fake(['Fake response']);

    $input = AgentUserInteraction::chat(chatInput([
        'resume' => [['interruptId' => 'call-1', 'status' => 'resolved', 'payload' => ['approved' => true]]],
    ]));

    expect(Chat::stream(new RememberingChatAgent, $input)->as($user))->not->toBeNull();
});

test('a store that cannot verify ownership refuses every conversation', function () {
    // The chat surface fails closed, so an application whose store never adopted the contract
    // sees a refusal rather than an unchecked conversation...
    app()->instance(ConversationStore::class, new FakeConversationStore);

    RememberingChatAgent::fake(['Fake response']);

    Chat::stream(new RememberingChatAgent, AgentUserInteraction::chat(chatInput()))->as(chatUser());
})->throws(ValidationException::class, 'The conversation does not belong to the authenticated user.');
