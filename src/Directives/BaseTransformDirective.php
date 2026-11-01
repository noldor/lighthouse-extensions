<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Directives;

use Nuwave\Lighthouse\Support\Utils;
use Nuwave\Lighthouse\Support\Contracts\ArgDirective;
use Nuwave\Lighthouse\Execution\Arguments\ArgumentSet;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Support\Contracts\ArgSanitizerDirective;

abstract class BaseTransformDirective extends BaseDirective implements ArgDirective, ArgSanitizerDirective
{
    /**
     * @param mixed $value The client given value
     *
     * @return mixed The transformed value
     */
    abstract protected function transformLeaf(mixed $value): mixed;

    final public function sanitize(mixed $argumentValue): mixed
    {
        return Utils::mapEachRecursive(
            fn (mixed $value): mixed => $value instanceof ArgumentSet
                ? $this->transformArgumentSet($value)
                : $this->transformLeaf($value),
            $argumentValue,
        );
    }

    protected function transformArgumentSet(ArgumentSet $argumentSet): ArgumentSet
    {
        foreach ($argumentSet->arguments as $argument) {
            $argument->value = $this->sanitize($argument->value);
        }

        return $argumentSet;
    }
}
