<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Tests\Integration;

use GraphQL\Error\Error;
use Noldors\LighthouseExtensions\Scalars\UUID;
use Noldors\LighthouseExtensions\Tests\TestCase;

final class TranslationsTest extends TestCase
{
    public function testScalarUsesTheCurrentLocaleOnEachInvocation(): void
    {
        $scalar = new UUID();
        $translator = $this->application()->make('translator');
        $translator->addLines(['messages.uuid' => 'Custom UUID message.'], 'ru', 'lighthouse-extensions');

        foreach (['en' => 'The value must be a UUID.', 'ru' => 'Custom UUID message.'] as $locale => $expected) {
            $this->application()->setLocale($locale);

            try {
                $scalar->parseValue('invalid');
                $this->fail(
                    'Invalid input must produce a GraphQL coercion error.',
                );
            } catch (Error $error) {
                $this->assertSame($expected, $error->getMessage());
            }
        }
    }
}
