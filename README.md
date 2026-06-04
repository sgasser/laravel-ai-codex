# Laravel AI Codex

Experimental Laravel AI provider for OpenAI Codex.

It registers one Laravel AI text provider:

```text
codex
```

The provider calls `https://chatgpt.com/backend-api/codex/responses` with Laravel AI's OpenAI Responses request shape. Text generation, streaming, structured output, and Laravel AI tools are supported.

## Install

```bash
composer require sgasser/laravel-ai-codex
php artisan vendor:publish --tag="codex-config"
```

Requirements:

- PHP 8.3+
- Laravel AI 0.7+
- Codex access token

## Configure

```dotenv
CODEX_URL=https://chatgpt.com/backend-api/codex
CODEX_MODEL=gpt-5.5
CODEX_TIMEOUT=300
CODEX_ACCESS_TOKEN=
CODEX_AUTH_JSON_PATH=~/.codex/auth.json
```

Authentication order:

1. `CODEX_ACCESS_TOKEN`
2. file-based Codex auth at `CODEX_AUTH_JSON_PATH`

## Use

```php
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

final class MenuExtractor implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Extract menu items and return concise JSON.';
    }
}

$response = (new MenuExtractor)->prompt(
    'Extract the menu items from this text and return JSON.',
    provider: 'codex',
    model: 'gpt-5.5',
    timeout: 300,
);
```

Direct provider access also works:

```php
use Laravel\Ai\Ai;

$response = Ai::textProvider('codex')->generateText(
    'Return exactly: OK',
    model: 'gpt-5.5',
);
```

## Security

This provider is experimental because `chatgpt.com/backend-api/codex` is not the public OpenAI API. Keep Codex access tokens server-side and out of source control.

## Test

```bash
composer quality
composer validate --strict --no-check-lock
composer audit
```

## License

MIT. See [LICENSE.md](LICENSE.md).
