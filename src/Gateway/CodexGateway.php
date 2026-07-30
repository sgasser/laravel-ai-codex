<?php

declare(strict_types=1);

namespace StefanGasser\LaravelAiCodex\Gateway;

use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Streaming\Events\Error;
use StefanGasser\LaravelAiCodex\Exceptions\CodexParseException;

final class CodexGateway extends OpenAiGateway
{
    /**
     * @param  array<int, Message>  $messages
     * @param  array<int, Tool>  $tools
     * @param  array<string, Type>|null  $schema
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $stream = $this->generateStreamStep(
            $this->generateEventId(),
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
            $timeout,
            $stepContext,
        );

        foreach ($stream as $event) {
            if ($event instanceof Error) {
                throw new AiException(sprintf('Codex stream error: [%s] %s', $event->type, $event->message));
            }
        }

        $response = $stream->getReturn();

        if (! $response instanceof StepResponse) {
            throw new AiException('Codex stream ended without a response.');
        }

        if (filled($schema)) {
            $decoded = json_decode($response->text, true);

            if (! is_array($decoded)) {
                throw CodexParseException::invalidJson(json_last_error_msg());
            }

            $structured = [];

            foreach ($decoded as $key => $value) {
                if (! is_string($key)) {
                    throw CodexParseException::invalidJson('Expected a JSON object.');
                }

                $structured[$key] = $value;
            }

            $response->structured = $structured;
        }

        return $response;
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

        foreach (parent::buildTextRequestBody(
            $provider,
            $model,
            null,
            $messages,
            $tools,
            $schema,
            $options,
        ) as $key => $value) {
            if (! is_string($key)) {
                throw new AiException('Codex request body contains a non-string key.');
            }

            $body[$key] = $value;
        }

        $body['instructions'] = $this->instructions($instructions);
        $body['store'] = false;

        return $body;
    }

    private function instructions(?string $instructions): string
    {
        return is_string($instructions) && mb_trim($instructions) !== ''
            ? mb_trim($instructions)
            : 'You are Codex. Return the requested result directly.';
    }
}
