<?php

declare(strict_types=1);

namespace StefanGasser\LaravelAiCodex\Auth;

use StefanGasser\LaravelAiCodex\Exceptions\CodexAuthException;

final readonly class CodexTokenResolver
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private array $config) {}

    public function token(): string
    {
        $configuredToken = $this->stringConfig('access_token');

        if ($configuredToken !== null) {
            return $configuredToken;
        }

        $authJsonPath = $this->authJsonPath();

        if ($authJsonPath === null || ! is_file($authJsonPath) || ! is_readable($authJsonPath)) {
            throw CodexAuthException::missingToken();
        }

        $payload = json_decode((string) file_get_contents($authJsonPath), true);

        if (! is_array($payload)) {
            throw CodexAuthException::invalidAuthFile($authJsonPath);
        }

        $token = $this->extractAccessToken($payload);

        if ($token === null) {
            throw CodexAuthException::missingToken();
        }

        return $token;
    }

    private function authJsonPath(): ?string
    {
        $path = $this->stringConfig('auth_json_path') ?? '~/.codex/auth.json';

        if (str_starts_with($path, '~/')) {
            $home = $this->homeDirectory();

            if ($home === null) {
                return null;
            }

            return $home.mb_substr($path, 1);
        }

        return $path;
    }

    private function homeDirectory(): ?string
    {
        foreach (['HOME', 'USERPROFILE'] as $name) {
            $value = getenv($name);

            if (is_string($value) && mb_trim($value) !== '') {
                return $this->withoutTrailingDirectorySeparators(mb_trim($value));
            }
        }

        $drive = getenv('HOMEDRIVE');
        $path = getenv('HOMEPATH');

        if (is_string($drive) && mb_trim($drive) !== '' && is_string($path) && mb_trim($path) !== '') {
            return $this->withoutTrailingDirectorySeparators(mb_trim($drive).mb_trim($path));
        }

        return null;
    }

    private function withoutTrailingDirectorySeparators(string $path): string
    {
        return preg_replace('/[\\\\\/]+$/', '', $path) ?? $path;
    }

    private function stringConfig(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }

    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function extractAccessToken(array $payload): ?string
    {
        foreach ([
            ['access_token'],
            ['tokens', 'access_token'],
            ['chatgpt', 'access_token'],
            ['credentials', 'access_token'],
        ] as $path) {
            $token = $this->valueAtPath($payload, $path);

            if (is_string($token) && mb_trim($token) !== '') {
                return mb_trim($token);
            }
        }

        return null;
    }

    /**
     * @param  array<mixed, mixed>  $payload
     * @param  list<string>  $path
     */
    private function valueAtPath(array $payload, array $path): mixed
    {
        $value = $payload;

        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
