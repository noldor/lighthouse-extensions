<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Integration;

use Override;
use Nuwave\Lighthouse\Schema\DirectiveLocator;
use Noldors\LighthouseExtensions\Tests\TestCase;
use Noldors\LighthouseExtensions\Overrides\DeleteDirective;
use Noldors\LighthouseExtensions\Overrides\UpdateDirective;

final class PersistenceOverridesTest extends TestCase
{
    public function testExplicitConfigurationEnablesPersistenceOverrides(): void
    {
        $locator = $this->application()->make(DirectiveLocator::class);
        $this->assertSame(DeleteDirective::class, $locator->resolve('delete'));
        $this->assertSame(UpdateDirective::class, $locator->resolve('update'));
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('lighthouse-extensions.overrides', ['delete' => true, 'update' => true]);
    }
}
