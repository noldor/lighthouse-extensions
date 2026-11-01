<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Directives;

use Override;
use DateTimeZone;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use GraphQL\Language\AST\NameNode;
use GraphQL\Language\AST\NodeList;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\StringValueNode;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use GraphQL\Language\AST\FieldDefinitionNode;
use Nuwave\Lighthouse\Schema\AST\DocumentAST;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Language\AST\InputValueDefinitionNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use Nuwave\Lighthouse\Exceptions\DefinitionException;
use Nuwave\Lighthouse\Exceptions\ValidationException;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\FieldManipulator;
use Noldors\LighthouseExtensions\Support\FormattedDateTime;

/**
 * @phpstan-type FormattingArguments array{timeZone?: string|null, format?: string|null}
 */
final class DateTimeDirective extends BaseDirective implements FieldManipulator, FieldMiddleware
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
            """
            Format Carbon values without mutating them.
            Adds optional timeZone and format String arguments when absent.
            Existing String argument metadata and validation are preserved.
            """
            directive @dateTime on FIELD_DEFINITION
            GRAPHQL;
    }

    #[Override]
    public function manipulateFieldDefinition(
        DocumentAST &$documentAST,
        FieldDefinitionNode &$fieldDefinition,
        InterfaceTypeDefinitionNode|ObjectTypeDefinitionNode &$parentType,
    ): void {
        $arguments = [
            'timeZone' => \__('lighthouse-extensions::schema.date_time.arguments.time_zone'),
            'format' => \__('lighthouse-extensions::schema.date_time.arguments.format'),
        ];
        $existing = [];

        foreach ($fieldDefinition->arguments as $argument) {
            $name = $argument->name->value;

            if (! \array_key_exists($name, $arguments)) {
                continue;
            }

            $type = $argument->type instanceof NonNullTypeNode ? $argument->type->type : $argument->type;

            if (! $type instanceof NamedTypeNode || 'String' !== $type->name->value) {
                $location = $parentType->name->value . '.' . $fieldDefinition->name->value;

                throw new DefinitionException("The {$name} argument for @dateTime on {$location} must have type String or String!.");
            }

            $existing[$name] = true;
        }

        foreach ($arguments as $name => $description) {
            if (isset($existing[$name])) {
                continue;
            }

            $fieldDefinition->arguments[] = new InputValueDefinitionNode([
                'name' => new NameNode(['value' => $name]),
                'type' => new NamedTypeNode(['name' => new NameNode(['value' => 'String'])]),
                'description' => new StringValueNode(['value' => $description]),
                'directives' => new NodeList([]),
            ]);
        }
    }

    public function handleField(FieldValue $fieldValue): void
    {
        $fieldValue->wrapResolver(
            fn (callable $resolver) => function (mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo) use ($resolver) {
                /** @var FormattingArguments $args */
                $dateTime = $resolver($root, $args, $context, $resolveInfo);

                if ($dateTime instanceof SyncPromise) {
                    return $dateTime->then(
                        fn (mixed $value) => $this->formatValue($args, $value, $resolveInfo),
                    );
                }

                return $this->formatValue($args, $dateTime, $resolveInfo);
            },
        );
    }

    /**
     * @param FormattingArguments $args
     * @param iterable<mixed, mixed> $dates
     *
     * @return Collection<array-key, FormattedDateTime>
     */
    private function handleIterable(array $args, iterable $dates, ResolveInfo $resolveInfo): Collection
    {
        $formatted = [];

        foreach ($dates as $key => $date) {
            if (! \is_int($key) && ! \is_string($key)) {
                throw new InvalidArgumentException('@dateTime iterable keys must be integers or strings.');
            }

            if (! $date instanceof CarbonInterface) {
                throw new InvalidArgumentException('@dateTime directive could be applied only to carbon fields.');
            }

            $formatted[$key] = $this->handleDateTime($args, $date, $resolveInfo);
        }

        return new Collection($formatted);
    }

    /**
     * @param FormattingArguments $args
     */
    private function handleDateTime(array $args, CarbonInterface $dateTime, ResolveInfo $resolveInfo): FormattedDateTime
    {
        if (isset($args['timeZone']) && ! \in_array($args['timeZone'], DateTimeZone::listIdentifiers(), true)) {
            throw ValidationException::withMessages([
                $resolveInfo->fieldName . '.timeZone' => [\__('lighthouse-extensions::messages.invalid_timezone')],
            ]);
        }

        $timezone = $args['timeZone'] ?? Config::string('lighthouse-extensions.timezone', 'UTC');
        $format = $args['format'] ?? Config::string('lighthouse-extensions.datetime_format', 'Y-m-d\\TH:i:s.uP');

        return new FormattedDateTime($dateTime->copy()->setTimezone(new DateTimeZone($timezone))->format($format));
    }

    /**
     * @param FormattingArguments $args
     *
     * @return FormattedDateTime|Collection<array-key, FormattedDateTime>|null
     */
    private function formatValue(array $args, mixed $dateTime, ResolveInfo $resolveInfo): Collection|FormattedDateTime|null
    {
        if (\is_iterable($dateTime)) {
            return $this->handleIterable($args, $dateTime, $resolveInfo);
        }

        if (null === $dateTime) {
            return null;
        }

        if (! $dateTime instanceof CarbonInterface) {
            throw new InvalidArgumentException('@dateTime directive could be applied only to carbon fields.');
        }

        return $this->handleDateTime($args, $dateTime, $resolveInfo);
    }
}
