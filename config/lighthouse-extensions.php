<?php

declare(strict_types = 1);

return [
    'date_format' => 'Y-m-d',
    'datetime_format' => 'Y-m-d\\TH:i:s.uP',
    'timezone' => 'UTC',
    /*
     * Opt-in replacements for Lighthouse's built-in @delete and @update directives.
     * Set an option to true to use this package's implementation; false leaves it unchanged.
     * Rebuild the schema cache and reload persistent workers after changing these options.
     */
    'overrides' => [
        /*
         * Replace the built-in @delete directive.
         * Returns deleted model identifiers (or a list of identifiers) instead of models,
         * preserving native deletion, authorization, transactions and model events.
         * Declare a scalar result type and an explicit model in the field's SDL.
         */
        'delete' => false,
        /*
         * Replace the built-in @update directive.
         * Also dispatches the model's mapped "updated" event for unchanged or relation-only
         * field updates when no attributes remain dirty and no native update event fired.
         * Dispatch stays inside the native mutation transaction and respects after-commit events.
         * Changed writes and nested argument-level @update keep their native behavior.
         */
        'update' => false,
    ],
];
