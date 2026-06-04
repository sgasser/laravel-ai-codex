<?php

declare(strict_types=1);

namespace StefanGasser\LaravelAiCodex\Exceptions;

final class CodexAuthException extends CodexException
{
    public static function missingToken(): self
    {
        return new self('Codex access token is missing. Set CODEX_ACCESS_TOKEN or use file-based Codex auth at ~/.codex/auth.json.');
    }

    public static function invalidAuthFile(string $path): self
    {
        return new self(sprintf('Codex auth file [%s] does not contain valid JSON.', $path));
    }
}
