<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Overrides;

use Override;
use LogicException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Schema\Directives\DeleteDirective as LighthouseDeleteDirective;

final class DeleteDirective extends LighthouseDeleteDirective
{
    #[Override]
    public function resolveField(FieldValue $fieldValue): callable
    {
        /** @var callable(mixed ...): (Collection<int, Model>|Model|null) $resolve */
        $resolve = parent::resolveField($fieldValue);

        return static function (mixed ...$args) use ($resolve): array|int|string|null {
            $result = $resolve(...$args);

            if ($result instanceof Collection) {
                return $result->modelKeys();
            }

            $id = $result?->getKey();

            if (null !== $id && ! \is_int($id) && ! \is_string($id)) {
                throw new LogicException('Deleted model must have an integer or string identifier.');
            }

            return $id;
        };
    }
}
