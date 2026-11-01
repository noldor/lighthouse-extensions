<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Integration\Directives;

use Illuminate\Support\Carbon;
use Nuwave\Lighthouse\Testing\MocksResolvers;
use Nuwave\Lighthouse\Testing\UsesTestSchema;
use Noldors\LighthouseExtensions\Tests\TestCase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

final class DateTimeValidationTest extends TestCase
{
    use MakesGraphQLRequests;
    use MocksResolvers;
    use UsesTestSchema;

    public function testInvalidTimezoneProducesAFieldValidationErrorWithoutMutatingSource(): void
    {
        $this->schema = <<<'GRAPHQL'
            scalar DateTime @scalar(class: "Noldors\\LighthouseExtensions\\Scalars\\DateTime")
            type Query { date(timeZone: String): DateTime @mock @dateTime }
            GRAPHQL;
        $this->setUpTestSchema();
        $date = Carbon::parse('2026-10-23T12:00:00Z');
        $this->mockResolver(static fn () => $date);
        $this->graphQL('{ date(timeZone: "not-a-timezone") }')->assertGraphQLValidationKeys(['date.timeZone']);
        $this->assertSame('2026-10-23T12:00:00+00:00', $date->toIso8601String());
    }
}
