<?php

declare(strict_types=1);

namespace StefanGasser\LaravelAiCodex\Tests;

use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use StefanGasser\LaravelAiCodex\CodexServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            AiServiceProvider::class,
            CodexServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        config()->set('codex.model', 'gpt-5.5');
        config()->set('codex.timeout', 300);
        config()->set('codex.auth_json_path', '~/.codex/auth.json');
    }
}
