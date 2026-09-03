<?php

use Laravel\Ai\Responses\Data;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

function textDelta(string $messageId, string $delta): TextDelta
{
    return new TextDelta(uniqid(), $messageId, $delta, time());
}

/** What every gateway yields exactly once at the top of a step. */
function stepStart(): StreamStart
{
    return new StreamStart(uniqid(), 'fake', 'fake-model', time());
}

test('combine joins deltas of a single message without separators', function () {
    $events = [
        textDelta('message-1', 'Hello'),
        textDelta('message-1', ' there'),
        textDelta('message-1', '!'),
    ];

    expect(TextDelta::combine($events))->toBe('Hello there!');
});

test('combine separates the text of different steps with a blank line', function () {
    $events = [
        stepStart(),
        textDelta('message-1', 'Let me look that up.'),
        new ToolCall(uniqid(), new Data\ToolCall('call-1', 'get_weather', ['city' => 'Copenhagen']), time()),
        new ToolResult(uniqid(), new Data\ToolResult('call-1', 'get_weather', ['city' => 'Copenhagen'], '12°C'), true, null, time()),
        stepStart(),
        textDelta('message-2', 'It is '),
        textDelta('message-2', '12°C in Copenhagen.'),
    ];

    expect(TextDelta::combine($events))->toBe("Let me look that up.\n\nIt is 12°C in Copenhagen.");
});

/*
 * What Anthropic sends once web search is on: the text block closes at every
 * citation and the next one opens mid-sentence, inside the same step. Grouping
 * by message ID gave ", and" a paragraph of its own, and a lone full stop after.
 */
test('combine keeps a step whole when a provider splits it around a citation', function () {
    $events = [
        stepStart(),
        textDelta('message-1', 'Laravel 13 is current, which'),
        textDelta('message-2', ' shipped in March'),
        textDelta('message-3', '.'),
    ];

    expect(TextDelta::combine($events))->toBe('Laravel 13 is current, which shipped in March.');
});

test('combine drops a step that produced only whitespace', function () {
    $events = [
        stepStart(),
        textDelta('message-1', 'First.'),
        stepStart(),
        textDelta('message-2', "\n"),
        stepStart(),
        textDelta('message-3', 'Second.'),
    ];

    expect(TextDelta::combine($events))->toBe("First.\n\nSecond.");
});

test('combine ignores events that are not text deltas', function () {
    $events = [
        new StreamStart(uniqid(), 'fake', 'fake-model', time()),
        textDelta('message-1', 'Only text.'),
    ];

    expect(TextDelta::combine($events))->toBe('Only text.');
});

test('combine returns an empty string when there are no text deltas', function () {
    expect(TextDelta::combine([]))->toBe('');
});
