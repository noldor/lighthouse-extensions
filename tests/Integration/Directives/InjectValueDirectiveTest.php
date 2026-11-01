<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Integration\Directives;

use Nuwave\Lighthouse\Testing\MocksResolvers;
use Nuwave\Lighthouse\Testing\UsesTestSchema;
use Noldors\LighthouseExtensions\Tests\TestCase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

final class InjectValueDirectiveTest extends TestCase
{
    use MakesGraphQLRequests;
    use MocksResolvers;
    use UsesTestSchema;

    public function testFixedServerValueOverridesClientValueAndSupportsRepeatedInjection(): void
    {
        $this->schema = <<<'GRAPHQL'
            type Query {
                value(kind: String): String! @mock
                    @injectValue(name: "kind", value: "fixed")
                    @injectValue(name: "source", value: "server")
            }
            GRAPHQL;
        $this->setUpTestSchema();
        $this->mockResolver(
            static function (mixed $root, array $args): string {
                self::assertIsString($args['kind']);
                self::assertIsString($args['source']);

                return $args['kind'] . ':' . $args['source'];
            },
        );
        $this->graphQL('{ value(kind: "client") }')->assertGraphQLErrorFree()->assertJsonPath(
            'data.value',
            'fixed:server',
        );
        $this->graphQL('{ value }')->assertGraphQLErrorFree()->assertJsonPath('data.value', 'fixed:server');
    }
}
