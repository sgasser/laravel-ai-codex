<?php

declare(strict_types=1);

namespace StefanGasser\LaravelAiCodex\Exceptions;

use Laravel\Ai\Exceptions\FailoverableException;
use RuntimeException;

class CodexException extends RuntimeException implements FailoverableException
{
    public static function redact(string $message): string
    {
        $patterns = [
            '/(sk-[A-Za-z0-9_-]{12,})/',
            '/(sess-[A-Za-z0-9_-]{12,})/',
            '/((?:OPENAI|CODEX|ANTHROPIC|GEMINI|AWS|AZURE|XAI|OPENROUTER)_[A-Z0-9_]*=)[^\s]+/',
            '/(Bearer\s+)[A-Za-z0-9._-]+/i',
        ];

        return preg_replace($patterns, '[redacted]', $message) ?? '[redacted]';
    }
}
