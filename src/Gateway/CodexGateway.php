<?php

declare(strict_types=1);

namespace StefanGasser\LaravelAiCodex\Gateway;

use Generator;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall as DataToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Psr\Http\Message\StreamInterface;
use StefanGasser\LaravelAiCodex\Exceptions\CodexParseException;

final class CodexGateway extends OpenAiGateway
{
    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $streamInputs = [];

    /**
     * @param  array<int, Message>  $messages
     * @param  array<int, Tool>  $tools
     * @param  array<string, Type>|null  $schema
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $providerName = $this->provider($provider)->name();
        /** @var Collection<int, object> $events */
        $events = new Collection;

        foreach ($this->streamText($this->generateEventId(), $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout) as $event) {
            if (! is_object($event)) {
                continue;
            }

            if ($event instanceof Error) {
                throw new AiException(sprintf('Codex stream error: [%s] %s', $event->type, $event->message));
            }

            $events->push($event);
        }

        $text = TextDelta::combine($events);
        $usage = StreamEnd::combineUsage($events);
        $toolCalls = $events->whereInstanceOf(ToolCallEvent::class)->map->toolCall->values();
        $toolResults = $events->whereInstanceOf(ToolResultEvent::class)->map->toolResult->values();
        $finishReason = $this->finishReason($events);
        $meta = new Meta($providerName, $model);
        $steps = new Collection([
            new Step($text, $toolCalls->all(), $toolResults->all(), $finishReason, $usage, $meta),
        ]);

        if (filled($schema)) {
            $structured = json_decode($text, true);

            if (! is_array($structured)) {
                throw CodexParseException::invalidJson(json_last_error_msg());
            }

            return (new StructuredTextResponse($structured, $text, $usage, $meta))
                ->withToolCallsAndResults($toolCalls, $toolResults)
                ->withSteps($steps);
        }

        return (new TextResponse($text, $usage, $meta))
            ->withToolCallsAndResults($toolCalls, $toolResults)
            ->withSteps($steps);
    }

    /**
     * @param  array<int, Message>  $messages
     * @param  array<int, Tool>  $tools
     * @param  array<string, Type>|null  $schema
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        $provider = $this->provider($provider);

        $body = $this->buildTextRequestBody(
            $provider, $model, $instructions, $messages, $tools, $schema, $options,
        );

        $this->streamInputs[$invocationId] = $this->inputList($body['input'] ?? null);

        try {
            yield from $this->processTextStream(
                $invocationId,
                $provider,
                $model,
                $tools,
                $schema,
                $options,
                $this->streamingResponseBody($provider, $body, $timeout),
                0,
                null,
                $timeout,
            );
        } finally {
            unset($this->streamInputs[$invocationId]);
        }
    }

    /**
     * Build the request body for the Codex Responses-compatible endpoint.
     *
     * @param  array<int, Message>  $messages
     * @param  array<int, Tool>  $tools
     * @param  array<string, Type>|null  $schema
     * @return array<string, mixed>
     */
    protected function buildTextRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        $body = [];

        foreach (parent::buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options) as $key => $value) {
            if (is_string($key)) {
                $body[$key] = $value;
            }
        }

        return [
            ...$body,
            'instructions' => $this->instructions($instructions),
            'store' => false,
            'stream' => true,
        ];
    }

    /**
     * Handle tool calls detected during streaming.
     *
     * @param  array<int, Tool>  $tools
     * @param  array<string, Type>|null  $schema
     * @param  array<int, array<string, mixed>>  $pendingToolCalls
     * @param  array<int, array<string, mixed>>  $reasoningItems
     */
    protected function handleStreamingToolCalls(
        string $invocationId,
        string $responseId,
        Provider $provider,
        string $model,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        array $pendingToolCalls,
        string $currentText,
        array $reasoningItems,
        int $depth,
        ?int $maxSteps,
        ?int $timeout = null,
    ): Generator {
        $mappedToolCalls = $this->mapStreamToolCalls($pendingToolCalls);
        $toolResults = [];

        foreach ($mappedToolCalls as $toolCall) {
            $toolResult = $this->executeStreamToolCall($toolCall, $tools);

            if (! $toolResult instanceof ToolResult) {
                continue;
            }

            yield (new ToolResultEvent(
                $this->generateEventId(),
                $toolResult,
                true,
                null,
                time(),
            ))->withInvocationId($invocationId);

            $toolResults[] = $toolResult;
        }

        if ($depth + 1 >= ($maxSteps ?? round(count($tools) * 1.5))) {
            yield (new StreamEnd(
                $this->generateEventId(),
                FinishReason::Stop->value,
                new Usage(0, 0),
                time(),
            ))->withInvocationId($invocationId);

            return;
        }

        $body = $this->toolFollowUpBody($invocationId, $model, $provider, $tools, $schema, $options, array_values($mappedToolCalls), $toolResults);

        $this->streamInputs[$invocationId] = $this->inputList($body['input']);

        yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $tools,
            $schema,
            $options,
            $this->streamingResponseBody($provider, $body, $timeout),
            $depth + 1,
            $maxSteps,
            $timeout,
        );
    }

    private function instructions(?string $instructions): string
    {
        return is_string($instructions) && mb_trim($instructions) !== ''
            ? mb_trim($instructions)
            : 'You are Codex. Return the requested result directly.';
    }

    private function provider(TextProvider $provider): Provider
    {
        if (! $provider instanceof Provider) {
            throw new AiException('Codex requires a Laravel AI Provider instance.');
        }

        return $provider;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function streamingResponseBody(Provider $provider, array $body, ?int $timeout): StreamInterface
    {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('responses', $body),
        );

        return $response->toPsrResponse()->getBody();
    }

    /**
     * @param  array<int, Tool>  $tools
     */
    private function executeStreamToolCall(DataToolCall $toolCall, array $tools): ?ToolResult
    {
        $tool = $this->findTool($toolCall->name, $tools);

        if (! $tool instanceof Tool) {
            return null;
        }

        return new ToolResult(
            $toolCall->id,
            $toolCall->name,
            $toolCall->arguments,
            $this->executeTool($tool, $toolCall->arguments),
            $toolCall->resultId,
        );
    }

    /**
     * @param  array<int, Tool>  $tools
     * @param  array<string, Type>|null  $schema
     * @param  array<int, DataToolCall>  $toolCalls
     * @param  array<int, ToolResult>  $toolResults
     * @return array<string, mixed>
     */
    private function toolFollowUpBody(
        string $invocationId,
        string $model,
        Provider $provider,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        array $toolCalls,
        array $toolResults,
    ): array {
        $body = [
            'model' => $model,
            'instructions' => $this->instructions(null),
            'input' => [
                ...($this->streamInputs[$invocationId] ?? []),
                ...$this->buildToolCallInput($toolCalls),
                ...$this->buildToolResultsInput($toolResults),
            ],
            'store' => false,
            'stream' => true,
        ];

        if (filled($tools)) {
            $body['tools'] = $this->mapTools($tools, $provider);
        }

        if (filled($schema)) {
            $body['text'] = $this->buildSchemaFormat($schema, Strict::isAppliedTo($options?->agent));
        }

        $body = array_merge($body, Arr::whereNotNull([
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
            'max_output_tokens' => $options?->maxTokens,
        ]));

        $providerOptions = $options?->providerOptions($provider->driver());

        return filled($providerOptions) ? array_merge($body, $providerOptions) : $body;
    }

    /**
     * @param  array<int, DataToolCall>  $toolCalls
     * @return array<int, array<string, mixed>>
     */
    private function buildToolCallInput(array $toolCalls): array
    {
        $input = [];

        foreach ($toolCalls as $toolCall) {
            $input[] = [
                'id' => $toolCall->id,
                'call_id' => $toolCall->resultId,
                'type' => 'function_call',
                'name' => $toolCall->name,
                'arguments' => json_encode($toolCall->arguments ?: (object) []),
            ];
        }

        return $input;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function inputList(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $items = [];

        foreach ($input as $item) {
            if (is_array($item)) {
                $items[] = $this->stringKeyedArray($item);
            }
        }

        return $items;
    }

    /**
     * @param  array<mixed, mixed>  $array
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $array): array
    {
        $normalized = [];

        foreach ($array as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  Collection<int, object>  $events
     */
    private function finishReason(Collection $events): FinishReason
    {
        $streamEnd = $events->whereInstanceOf(StreamEnd::class)->last();

        if (! $streamEnd instanceof StreamEnd) {
            return FinishReason::Unknown;
        }

        return FinishReason::tryFrom($streamEnd->reason) ?? FinishReason::Unknown;
    }
}
