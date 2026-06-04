<?php

declare(strict_types=1);

namespace StefanGasser\LaravelAiCodex\Providers;

use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\StreamsText;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\TextResponse;
use StefanGasser\LaravelAiCodex\Auth\CodexTokenResolver;

final class CodexProvider extends Provider implements SupportsFileSearch, SupportsWebSearch, TextProvider
{
    use GeneratesText;
    use HasTextGateway;
    use StreamsText;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        OpenAiGateway $gateway,
        array $config,
        private readonly CodexTokenResolver $tokenResolver,
        \Illuminate\Contracts\Events\Dispatcher $events,
    ) {
        parent::__construct($gateway, $config, $events);
    }

    public function textGateway(): TextGateway
    {
        return $this->textGateway ?? $this->gateway;
    }

    /**
     * @return array{key: string}
     */
    public function providerCredentials(): array
    {
        return [
            'key' => $this->tokenResolver->token(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function additionalConfiguration(): array
    {
        $configuration = [];

        foreach (parent::additionalConfiguration() as $key => $value) {
            if (is_string($key)) {
                $configuration[$key] = $value;
            }
        }

        return [
            ...$configuration,
            'url' => $this->stringConfig('url', 'https://chatgpt.com/backend-api/codex'),
        ];
    }

    /**
     * Get the file search tool options for the provider.
     *
     * @return array<string, mixed>
     */
    public function fileSearchToolOptions(FileSearch $search): array
    {
        return array_filter([
            'vector_store_ids' => $search->ids(),
            'filters' => filled($search->filters) ? [
                'type' => 'and',
                'filters' => (new Collection($search->filters))->map(fn (array $filter): array => [
                    'type' => $filter['type'],
                    'key' => $filter['key'],
                    'value' => $filter['value'],
                ])->all(),
            ] : null,
        ]);
    }

    /**
     * Get the web search tool options for the provider.
     *
     * @return array<string, mixed>
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        return array_filter([
            'filters' => filled($search->allowedDomains)
                ? ['allowed_domains' => $search->allowedDomains]
                : null,
            'user_location' => $search->hasLocation()
                ? array_filter([
                    'type' => 'approximate',
                    'city' => $search->city,
                    'region' => $search->region,
                    'country' => $search->country,
                ])
                : null,
        ]);
    }

    public function defaultTextModel(): string
    {
        return $this->modelConfig('default') ?? $this->stringConfig('default_model', 'gpt-5.5');
    }

    public function cheapestTextModel(): string
    {
        return $this->modelConfig('cheapest') ?? $this->defaultTextModel();
    }

    public function smartestTextModel(): string
    {
        return $this->modelConfig('smartest') ?? $this->defaultTextModel();
    }

    /**
     * @param  array<string, Type>|null  $schema
     */
    public function generateText(
        string $prompt,
        ?string $model = null,
        ?int $timeout = null,
        ?array $schema = null,
    ): TextResponse {
        return $this->textGateway()->generateText(
            $this,
            $model ?? $this->defaultTextModel(),
            null,
            [new UserMessage($prompt)],
            [],
            $schema,
            new TextGenerationOptions,
            $timeout,
        );
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : $default;
    }

    private function modelConfig(string $key): ?string
    {
        $models = $this->config['models'] ?? null;

        if (! is_array($models)) {
            return null;
        }

        $value = $models[$key] ?? null;

        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }
}
