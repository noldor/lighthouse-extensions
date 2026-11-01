<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Integration;

use Nuwave\Lighthouse\Schema\DirectiveLocator;
use Noldors\LighthouseExtensions\Tests\TestCase;
use Nuwave\Lighthouse\Schema\Directives\DeleteDirective;
use Nuwave\Lighthouse\Schema\Directives\UpdateDirective;
use Noldors\LighthouseExtensions\Directives\LowerDirective;

final class ProviderRegistrationTest extends TestCase
{
    public function testInstallingPackageKeepsNativePersistenceDirectivesAndRegistersExtensions(): void
    {
        $locator = $this->application()->make(DirectiveLocator::class);
        $this->assertSame(DeleteDirective::class, $locator->resolve('delete'));
        $this->assertSame(UpdateDirective::class, $locator->resolve('update'));
        $this->assertSame(LowerDirective::class, $locator->resolve('lower'));
    }
}
