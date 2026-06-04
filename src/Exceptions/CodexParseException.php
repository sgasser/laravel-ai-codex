<?php

declare(strict_types=1);

namespace StefanGasser\LaravelAiCodex\Exceptions;

final class CodexParseException extends CodexException
{
    public static function invalidJson(string $context): self
    {
        return new self('Unable to parse Codex JSON output: '.self::redact($context));
    }
}
