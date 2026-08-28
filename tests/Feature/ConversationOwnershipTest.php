<?php

use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\ConversationOwnershipException;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Tests\Fixtures\Agents\RememberingAssistantAgent;

test('a conversation belongs only to the participant it was stored for', function (): void {
    $store = new DatabaseConversationStore;

    $conversationId = $store->storeConversation('user', 1, 'Hello');

    expect($store->conversationBelongsTo($conversationId, 'user', 1))->toBeTrue()
        ->and($store->conversationBelongsTo($conversationId, 'user', 2))->toBeFalse()
        ->and($store->conversationBelongsTo($conversationId, 'admin', 1))->toBeFalse();
});

test('a conversation stored without a participant belongs to nobody in particular', function (): void {
    $store = new DatabaseConversationStore;

    $conversationId = $store->storeConversation(null, null, 'Hello');

    expect($store->conversationBelongsTo($conversationId, 'user', 1))->toBeTrue();
});

test('an unknown conversation is not refused here', function (): void {
    $store = new DatabaseConversationStore;

    // Nothing to compare against, and the rest of the run already deals with a missing conversation...
    expect($store->conversationBelongsTo('missing-conversation', 'user', 1))->toBeTrue();
});

test('reading a conversation that belongs to somebody else is refused', function (): void {
    $store = new DatabaseConversationStore;

    $conversationId = $store->storeConversation('user', 1, 'Hello');

    $intruder = new class
    {
        public int $id = 2;
    };

    $agent = (new RememberingAssistantAgent)->continue($conversationId, as: $intruder);

    expect(fn () => $agent->messages())
        ->toThrow(ConversationOwnershipException::class);
});

test('reading your own conversation is not refused', function (): void {
    $store = new DatabaseConversationStore;

    $participant = new class
    {
        public int $id = 7;
    };

    $conversationId = $store->storeConversation(
        Conversation::participantType($participant),
        Conversation::participantKey($participant),
        'Hello',
    );

    $store->storeConversation('user', 8, 'Somebody else');

    $agent = (new RememberingAssistantAgent)->continue($conversationId, as: $participant);

    expect($agent->messages())->toBe([])
        ->and(DB::table('agent_conversations')->count())->toBe(2);
});

test('a conversation continued without a participant is read as before', function (): void {
    $store = new DatabaseConversationStore;

    $conversationId = $store->storeConversation('user', 1, 'Hello');

    // Nobody was named, so there is nothing to check the conversation against...
    $agent = (new RememberingAssistantAgent)->continue($conversationId);

    expect($agent->messages())->toBe([]);
});

test('a turn aimed at somebody else conversation is refused before it is stored', function (): void {
    $store = new DatabaseConversationStore;

    $conversationId = $store->storeConversation('user', 1, 'Hello');

    $intruder = new class
    {
        public int $id = 2;
    };

    RememberingAssistantAgent::fake(['Answer']);

    // The guard runs in the middleware, so a turn is refused whether or not the agent loads history...
    expect(fn () => (new RememberingAssistantAgent)->continue($conversationId, as: $intruder)->prompt('Inject'))
        ->toThrow(ConversationOwnershipException::class)
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->count())->toBe(0);
});
