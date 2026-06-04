<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Tools\Request;
use StefanGasser\LaravelAiCodex\Auth\CodexTokenResolver;
use StefanGasser\LaravelAiCodex\Exceptions\CodexAuthException;
use StefanGasser\LaravelAiCodex\Providers\CodexProvider;

it('registers the experimental codex provider with Laravel AI', function (): void {
    config()->set('ai.providers.codex.access_token', 'test-codex-token');
    Ai::forgetInstance('codex');

    $provider = Ai::textProvider('codex');

    expect($provider)->toBeInstanceOf(Provider::class);
    expect($provider)->toBeInstanceOf(CodexProvider::class);

    if (! $provider instanceof Provider) {
        throw new RuntimeException('Codex provider was not registered correctly.');
    }

    expect($provider->name())->toBe('codex');
    expect(config('ai.providers.codex.driver'))->toBe('codex');
});

it('calls the codex responses endpoint with codex auth', function (): void {
    config()->set('ai.providers.codex.access_token', 'test-codex-token');
    config()->set('ai.providers.codex.url', 'https://chatgpt.com/backend-api/codex');
    Ai::forgetInstance('codex');

    Http::fake([
        'https://chatgpt.com/backend-api/codex/responses' => Http::response(codexTextStream('Hello from Codex.'), 200),
    ]);

    $provider = Ai::textProvider('codex');
    $response = $provider->textGateway()->generateText(
        $provider,
        'gpt-5.5',
        'Be direct.',
        [new UserMessage('Say hello.')],
    );

    expect($response->text)->toBe('Hello from Codex.');
    expect($response->usage->promptTokens)->toBe(8);
    expect($response->usage->completionTokens)->toBe(5);
    expect($response->meta->provider)->toBe('codex');

    Http::assertSent(function (HttpRequest $request): bool {
        $data = httpRequestData($request);

        return $request->url() === 'https://chatgpt.com/backend-api/codex/responses'
            && $request->hasHeader('Authorization', 'Bearer test-codex-token')
            && ($data['model'] ?? null) === 'gpt-5.5'
            && ($data['instructions'] ?? null) === 'Be direct.'
            && ($data['store'] ?? null) === false
            && ($data['stream'] ?? null) === true
            && str_contains(json_encode($data['input'] ?? null, JSON_THROW_ON_ERROR), 'Say hello.');
    });
});

it('generates text through the codex convenience method', function (): void {
    config()->set('ai.providers.codex.access_token', 'test-codex-token');
    config()->set('ai.providers.codex.url', 'https://chatgpt.com/backend-api/codex');
    Ai::forgetInstance('codex');

    Http::fake([
        'https://chatgpt.com/backend-api/codex/responses' => Http::response(codexTextStream('Convenience works.'), 200),
    ]);

    $provider = Ai::textProvider('codex');

    if (! $provider instanceof CodexProvider) {
        throw new RuntimeException('Codex provider was not registered correctly.');
    }

    $response = $provider->generateText('Say it works.', model: 'gpt-5.5');

    expect($response->text)->toBe('Convenience works.');
});

it('supports Laravel AI tool callbacks through the codex provider', function (): void {
    config()->set('ai.providers.codex.access_token', 'test-codex-token');
    Ai::forgetInstance('codex');

    Http::fake([
        'https://chatgpt.com/backend-api/codex/responses' => Http::sequence()
            ->push(codexToolCallStream(), 200)
            ->push(codexTextStream('Tool returned: tool:pong', 'resp_2'), 200),
    ]);

    $provider = Ai::textProvider('codex');
    $response = $provider->textGateway()->generateText(
        $provider,
        'gpt-5.5',
        null,
        [new UserMessage('Use the tool.')],
        [new CodexEchoTool],
    );

    expect($response->text)->toBe('Tool returned: tool:pong');
    expect($response->toolCalls)->toHaveCount(1);
    expect($response->toolResults)->toHaveCount(1);

    Http::assertSentCount(2);
    Http::assertSent(function (HttpRequest $request): bool {
        $data = httpRequestData($request);
        $tools = arrayValue($data['tools'] ?? null);
        $tool = arrayValue($tools[0] ?? null);

        return ($data['store'] ?? null) === false
            && ($data['stream'] ?? null) === true
            && ($tool['type'] ?? null) === 'function'
            && ($tool['name'] ?? null) === 'CodexEchoTool';
    });
    Http::assertSent(function (HttpRequest $request): bool {
        $data = httpRequestData($request);
        $input = arrayValue($data['input'] ?? null);
        $functionCall = arrayValue($input[1] ?? null);
        $toolResult = arrayValue($input[2] ?? null);

        return ! array_key_exists('previous_response_id', $data)
            && ($data['instructions'] ?? null) === 'You are Codex. Return the requested result directly.'
            && ($data['store'] ?? null) === false
            && ($data['stream'] ?? null) === true
            && ($functionCall['type'] ?? null) === 'function_call'
            && ($functionCall['call_id'] ?? null) === 'call_1'
            && ($functionCall['name'] ?? null) === 'CodexEchoTool'
            && ($toolResult['type'] ?? null) === 'function_call_output'
            && ($toolResult['call_id'] ?? null) === 'call_1'
            && ($toolResult['output'] ?? null) === 'tool:pong';
    });
});

it('resolves codex access tokens from file based codex auth', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'codex-auth-');

    if ($path === false) {
        throw new RuntimeException('Unable to create temporary auth file.');
    }

    file_put_contents($path, json_encode(['access_token' => 'file-token'], JSON_THROW_ON_ERROR));

    $resolver = new CodexTokenResolver(['auth_json_path' => $path]);

    expect($resolver->token())->toBe('file-token');
});

it('expands auth paths from windows user profile environment variables', function (): void {
    $home = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codex-home-'.bin2hex(random_bytes(4));
    $authDirectory = $home.DIRECTORY_SEPARATOR.'.codex';

    if (! mkdir($authDirectory, 0777, true) && ! is_dir($authDirectory)) {
        throw new RuntimeException('Unable to create temporary Codex auth directory.');
    }

    file_put_contents($authDirectory.DIRECTORY_SEPARATOR.'auth.json', json_encode(['access_token' => 'windows-file-token'], JSON_THROW_ON_ERROR));

    $previousHome = getenv('HOME');
    $previousUserProfile = getenv('USERPROFILE');

    try {
        putenv('HOME');
        putenv('USERPROFILE='.$home);

        $resolver = new CodexTokenResolver(['auth_json_path' => '~/.codex/auth.json']);

        expect($resolver->token())->toBe('windows-file-token');
    } finally {
        restoreEnv('HOME', $previousHome);
        restoreEnv('USERPROFILE', $previousUserProfile);
    }
});

it('throws when codex auth is unavailable', function (): void {
    $resolver = new CodexTokenResolver(['auth_json_path' => '/missing/codex/auth.json']);

    $resolver->token();
})->throws(CodexAuthException::class);

/**
 * @return non-empty-string
 */
function codexTextStream(string $text, string $id = 'resp_1'): string
{
    return codexSse([
        [
            'type' => 'response.created',
            'response' => [
                'id' => $id,
                'model' => 'gpt-5.5',
            ],
        ],
        [
            'type' => 'response.output_text.delta',
            'delta' => $text,
        ],
        [
            'type' => 'response.output_text.done',
        ],
        [
            'type' => 'response.completed',
            'response' => codexTextResponse($text, $id),
        ],
    ]);
}

/**
 * @return array<string, mixed>
 */
function codexTextResponse(string $text, string $id): array
{
    return [
        'id' => $id,
        'status' => 'completed',
        'model' => 'gpt-5.5',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [[
                'type' => 'output_text',
                'text' => $text,
                'annotations' => [],
            ]],
        ]],
        'usage' => [
            'input_tokens' => 10,
            'input_tokens_details' => ['cached_tokens' => 2],
            'output_tokens' => 5,
            'output_tokens_details' => ['reasoning_tokens' => 1],
        ],
    ];
}

/**
 * @return non-empty-string
 */
function codexToolCallStream(): string
{
    return codexSse([
        [
            'type' => 'response.created',
            'response' => [
                'id' => 'resp_1',
                'model' => 'gpt-5.5',
            ],
        ],
        [
            'type' => 'response.output_item.added',
            'output_index' => 0,
            'item' => [
                'id' => 'fc_1',
                'type' => 'function_call',
                'call_id' => 'call_1',
                'name' => 'CodexEchoTool',
            ],
        ],
        [
            'type' => 'response.function_call_arguments.done',
            'item_id' => 'fc_1',
            'arguments' => '{"value":"pong"}',
        ],
        [
            'type' => 'response.completed',
            'response' => codexToolCallResponse(),
        ],
    ]);
}

/**
 * @return array<string, mixed>
 */
function codexToolCallResponse(): array
{
    return [
        'id' => 'resp_1',
        'status' => 'completed',
        'model' => 'gpt-5.5',
        'output' => [[
            'id' => 'fc_1',
            'type' => 'function_call',
            'status' => 'completed',
            'call_id' => 'call_1',
            'name' => 'CodexEchoTool',
            'arguments' => '{"value":"pong"}',
        ]],
        'usage' => [
            'input_tokens' => 12,
            'input_tokens_details' => ['cached_tokens' => 0],
            'output_tokens' => 3,
            'output_tokens_details' => ['reasoning_tokens' => 0],
        ],
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $events
 * @return non-empty-string
 */
function codexSse(array $events): string
{
    $stream = '';

    foreach ($events as $event) {
        $stream .= 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n";
    }

    return $stream.'data: [DONE]'."\n\n";
}

/**
 * @return array<string, mixed>
 */
function httpRequestData(HttpRequest $request): array
{
    return stringKeyedArray($request->data());
}

/**
 * @return array<mixed>
 */
function arrayValue(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * @param  array<mixed, mixed>  $array
 * @return array<string, mixed>
 */
function stringKeyedArray(array $array): array
{
    $normalized = [];

    foreach ($array as $key => $value) {
        if (is_string($key)) {
            $normalized[$key] = $value;
        }
    }

    return $normalized;
}

function restoreEnv(string $name, string|false $value): void
{
    if ($value === false) {
        putenv($name);

        return;
    }

    putenv($name.'='.$value);
}

final class CodexEchoTool implements Tool
{
    public function description(): string
    {
        return 'Echo a value for tests.';
    }

    public function handle(Request $request): string
    {
        return 'tool:'.$request->string('value')->toString();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'value' => $schema->string()->required(),
        ];
    }
}
