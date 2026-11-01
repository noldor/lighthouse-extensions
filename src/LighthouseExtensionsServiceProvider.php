<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Nuwave\Lighthouse\Events\StartExecution;
use Nuwave\Lighthouse\Schema\DirectiveLocator;
use Nuwave\Lighthouse\Events\RegisterDirectiveNamespaces;
use Noldors\LighthouseExtensions\Overrides\DeleteDirective;
use Noldors\LighthouseExtensions\Overrides\UpdateDirective;
use Noldors\LighthouseExtensions\Support\PrepareFieldResolvers;

final class LighthouseExtensionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/lighthouse-extensions.php', 'lighthouse-extensions');
        $this->app->singleton(PrepareFieldResolvers::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'lighthouse-extensions');

        $this->publishes([
            __DIR__ . '/../config/lighthouse-extensions.php' => $this->app->configPath('lighthouse-extensions.php'),
        ], 'lighthouse-extensions-config');

        $this->publishes([
            __DIR__ . '/../lang' => $this->app->langPath('vendor/lighthouse-extensions'),
        ], 'lighthouse-extensions-translations');

        $this->publishes([
            __DIR__ . '/../resources/graphql/scalars.graphql' => $this->app->basePath(
                'graphql/lighthouse-extensions-scalars.graphql',
            ),
        ], 'lighthouse-extensions-scalars');

        Event::listen(RegisterDirectiveNamespaces::class, static fn (): string => __NAMESPACE__ . '\\Directives');
        Event::listen(StartExecution::class, PrepareFieldResolvers::class);

        $this->callAfterResolving(DirectiveLocator::class, static function (DirectiveLocator $locator): void {
            if (Config::boolean('lighthouse-extensions.overrides.delete', false)) {
                $locator->setResolved('delete', DeleteDirective::class);
            }

            if (Config::boolean('lighthouse-extensions.overrides.update', false)) {
                $locator->setResolved('update', UpdateDirective::class);
            }
        });
    }
}
