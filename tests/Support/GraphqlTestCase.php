<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Support;

use Override;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Nuwave\Lighthouse\Testing\MocksResolvers;
use Nuwave\Lighthouse\Testing\UsesTestSchema;
use Nuwave\Lighthouse\Schema\DirectiveLocator;
use Noldors\LighthouseExtensions\Tests\TestCase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Noldors\LighthouseExtensions\Overrides\DeleteDirective;
use Noldors\LighthouseExtensions\Overrides\UpdateDirective;

abstract class GraphqlTestCase extends TestCase
{
    use MakesGraphQLRequests;
    use MocksResolvers;
    use UsesTestSchema;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('extension_records');
        Schema::create('extension_records', static function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('extension_records');
            $table->timestampsTz(6);
        });
        $this->application()->make(DirectiveLocator::class)->setResolved('delete', DeleteDirective::class)->setResolved(
            'update',
            UpdateDirective::class,
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        Schema::dropIfExists('extension_records');
        parent::tearDown();
    }

    protected function useSchema(string $schema): void
    {
        $this->schema = 'scalar SerialID @scalar(class: "Noldors\\\\LighthouseExtensions\\\\Scalars\\\\SerialID")' . "\n" . $schema;
        $this->setUpTestSchema();
    }
}
