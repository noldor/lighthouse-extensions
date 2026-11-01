<?php

declare(strict_types = 1);

namespace Noldors\LighthouseExtensions\Overrides;

use Override;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nuwave\Lighthouse\Execution\Arguments\ArgumentSet;
use Nuwave\Lighthouse\Schema\Directives\UpdateDirective as LighthouseUpdateDirective;

final class UpdateDirective extends LighthouseUpdateDirective
{
    /**
     * @inheritDoc
     */
    #[Override]
    protected function executeMutation(Model $model, ArgumentSet|array $args, Relation|null $parentRelation = null): array|Model
    {
        $mutated = parent::executeMutation($model, $args, $parentRelation);

        // Field mutations invoke this inside Lighthouse's configured transaction, before refresh.
        if (null === $parentRelation && $mutated instanceof Model) {
            $this->dispatchMissingUpdatedEvent($mutated);
        }

        return $mutated;
    }

    private function dispatchMissingUpdatedEvent(Model $model): void
    {
        // A vetoed dirty save has not persisted the requested attributes.
        if ($model->isDirty() || $model->wasChanged()) {
            return;
        }

        $eventClass = $model->dispatchesEvents()['updated'] ?? null;

        if (null !== $eventClass) {
            Event::dispatch(new $eventClass($model));
        }
    }
}
