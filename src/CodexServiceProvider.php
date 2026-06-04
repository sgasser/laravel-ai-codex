<?php

declare(strict_types=1);

namespace StefanGasser\LaravelAiCodex;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use StefanGasser\LaravelAiCodex\Auth\CodexTokenResolver;
use StefanGasser\LaravelAiCodex\Gateway\CodexGateway;
use StefanGasser\LaravelAiCodex\Providers\CodexProvider;

final class CodexServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('codex')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->registerAiProviderConfig();
        $this->extendAiManager();
    }

    private function extendAiManager(): void
    {
        $normalizeConfig = fn (array $config): array => $this->stringKeyedArray($config);

        $extend = function (AiManager $manager) use ($normalizeConfig): void {
            $manager->extend('codex', function (Application $app, array $config) use ($normalizeConfig): TextProvider {
                $config = $normalizeConfig($config);

                return new CodexProvider(
                    new CodexGateway($app->make(Dispatcher::class)),
                    $config,
                    new CodexTokenResolver($config),
                    $app->make(Dispatcher::class),
                );
            });
        };

        $this->app->afterResolving(AiManager::class, $extend);

        if ($this->app->resolved(AiManager::class)) {
            $extend($this->app->make(AiManager::class));
        }
    }

    private function registerAiProviderConfig(): void
    {
        $config = $this->app->make(ConfigRepository::class);
        $packageConfig = $this->arrayConfig($config->get('codex', []));
        $existing = $this->arrayConfig($config->get('ai.providers.codex', []));

        $config->set('ai.providers.codex', array_replace_recursive([
            'driver' => 'codex',
            'name' => 'codex',
            'url' => $this->stringConfig($packageConfig, 'url', 'https://chatgpt.com/backend-api/codex'),
            'default_model' => $this->stringConfig($packageConfig, 'model', 'gpt-5.5'),
            'timeout' => $this->positiveIntConfig($packageConfig, 'timeout', 300),
            'access_token' => $this->nullableStringConfig($packageConfig, 'access_token'),
            'auth_json_path' => $this->stringConfig($packageConfig, 'auth_json_path', '~/.codex/auth.json'),
        ], $existing));
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayConfig(mixed $value): array
    {
        return is_array($value) ? $this->stringKeyedArray($value) : [];
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
     * @param  array<string, mixed>  $config
     */
    private function stringConfig(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : $default;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function nullableStringConfig(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function positiveIntConfig(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        if (is_int($value)) {
            return max(1, $value);
        }

        if (is_string($value) && ctype_digit($value)) {
            return max(1, (int) $value);
        }

        return $default;
    }
}
