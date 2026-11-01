# Lighthouse extensions

Reusable directives and scalars for Laravel Lighthouse. Requires PHP 8.5, Laravel 13, and Lighthouse 6.72.5 or later within 6.x. PostgreSQL is used for the transaction and row-lock integration tests. This package has no dependency on an application's models, providers, Carbon macros, or idempotency implementation.

## Installation

```sh
composer require noldors/lighthouse-extensions
php artisan vendor:publish --tag=lighthouse-extensions-config
```

Laravel discovers `Noldors\LighthouseExtensions\LighthouseExtensionsServiceProvider`. If discovery is disabled, register that provider in the application. This package is independent of `noldors/lighthouse-idempotency`; either package can be installed alone.

Declare the scalars your schema uses explicitly:

```graphql
scalar Date @scalar(class: "Noldors\\LighthouseExtensions\\Scalars\\Date")
scalar DateTime @scalar(class: "Noldors\\LighthouseExtensions\\Scalars\\DateTime")
scalar SerialID @scalar(class: "Noldors\\LighthouseExtensions\\Scalars\\SerialID")
scalar UUID @scalar(class: "Noldors\\LighthouseExtensions\\Scalars\\UUID")
```

Scalar declarations are not injected automatically, so an application controls their public names and avoids duplicate definitions.

## Configuration and dates

`config/lighthouse-extensions.php` contains:

```php
return [
    'date_format' => 'Y-m-d',
    'datetime_format' => 'Y-m-d\\TH:i:s.uP',
    'timezone' => 'UTC',
    'overrides' => [
        'delete' => false,
        'update' => false,
    ],
];
```

Formats use PHP date-format tokens. These settings belong to this package; `app.date_format`, `app.datetime_format`, and `app.timezone` are not consulted. No global or instance-local Carbon macros are registered or required.

`Date` parses according to `date_format`, rejects invalid calendar components, and returns a Carbon value at midnight UTC. Serialization formats the calendar date in UTC. `DateTime` parses according to `datetime_format` and normalizes to UTC. An explicit offset in the input wins; otherwise `timezone` supplies the input timezone. Serialization formats a copy of the value in the configured timezone. Invalid dates, times, offset minutes, and offset seconds are rejected rather than silently normalized. Date strings returned by ordinary resolvers must match the configured input format; arbitrary strings are not accepted as preformatted output.

Use `@dateTime` on a `DateTime` field to support a client-selected timezone and format. The directive automatically adds nullable `timeZone: String` and `format: String` arguments:

```graphql
type Report {
    generatedAt: DateTime @dateTime
    dates: [DateTime!]! @dateTime
}
```

```graphql
query {
    report {
        generatedAt(timeZone: "Europe/Paris", format: "Y-m-d H:i")
    }
}
```

Omitted or null arguments use `timezone` and `datetime_format`. Auto-added arguments have no SDL defaults, so configuration is read when the field executes. Existing `String` or `String!` declarations retain their descriptions, defaults, and validation directives; only a missing argument is added. An incompatible existing type fails during schema construction. A bad timezone produces a Lighthouse validation error at `<field>.timeZone`. A resolver must return Carbon/CarbonImmutable, an iterable of Carbon values, or null for a nullable field. Deferred values using Lighthouse's synchronous promise adapter are supported. Dates are cloned before formatting, including list values. A small internal formatted-value object carries directive output to the package `DateTime` scalar without modifying Carbon objects or weakening scalar input validation. Pair `@dateTime` with this scalar, rather than a plain `String` or another package's `DateTime`.

## Identifier scalars

`SerialID` accepts positive integers or integer strings within PHP's integer range and returns integers to resolvers. Output is a string, allowing IDs larger than GraphQL's signed 32-bit `Int`. It uses PHP's integer filter semantics, including an optional plus sign or surrounding whitespace. It rejects zero, negative values, floats, booleans, overflow, arrays, and numeric prefixes.

`UUID` accepts canonical hyphenated UUID strings of any version, including nil and max UUIDs. It normalizes input and output to lowercase. Non-string inputs, whitespace, braces, URNs, and missing hyphens are rejected. It does not impose a UUIDv7 requirement.

## Argument transformations

`@lower` lowercases string arguments and input fields before Laravel validation. Nested argument sets and lists are traversed; non-string values and null are preserved.

```graphql
input ContactInput {
    email: String! @lower @rules(apply: ["email"])
}

type Mutation {
    saveContact(input: ContactInput! @spread): Contact! @create
}
```

`@injectValue` injects fixed server-defined strings into Lighthouse's argument set. It is repeatable and overrides a client-provided value with the same name.

```graphql
type Mutation {
    createEntry(title: String!): Entry!
        @injectValue(name: "kind", value: "manual")
        @injectValue(name: "source", value: "api")
        @create
}
```

A fixed injected value does not authorize the operation. Keep the application's validators and policy directives.

## Row locking

`@lockForUpdate` starts a transaction on the declared model's connection and locks a numeric-ID row before invoking Lighthouse's complete native argument transformation, validation, authorization, and resolver pipeline.

```graphql
input ChangeInput {
    id: SerialID! @rules(apply: ["exists:entries,id"])
    title: String!
}

type Mutation {
    changeEntry(input: ChangeInput! @spread): Entry!
        @lockForUpdate(model: "Entry", argument: "input.id", parent: "folder")
        @update
}
```

`model` accepts Lighthouse model namespace resolution or a fully qualified model class. `argument` selects an original public argument using a dot-separated path; spreading and renaming do not change that selector. Lists and missing/non-scalar selector paths are rejected while building the schema. Validate existence, ownership, and operation-specific constraints in the normal Laravel/Lighthouse pipeline. The directive does not replace those checks. An invalid numeric selector is left to that pipeline without acquiring a row lock.

The optional `parent` is an immutable `belongsTo` or `hasOne` relation. The related row is locked before the target row. Both models must use the same database connection. A mutable parent relationship needs an operation-specific concurrency design. All protected writes and checks must use that connection; a Laravel transaction cannot make writes on another connection atomic.

Use only on mutation fields with synchronous resolvers. Deferred or promise results are rejected and the transaction rolls back. Resolver failures also roll back. The guarantee belongs to the annotated field, including an annotated leaf below namespaced mutation fields; it does not make a whole GraphQL document atomic. Multiple writers must follow the same locking order.

The provider prepares lock wrappers through Lighthouse's `StartExecution` event, without replacing its `FieldFactory`. Each authored object field is materialized once per schema. Schema compilation errors in another authored field can therefore reject a request before its selected field executes. Weak schema/field markers prevent repeated wrapping of retained schemas.

## Optional native persistence overrides

Installation leaves Lighthouse's `@delete` and `@update` unchanged. Enable each adapter explicitly:

```php
'overrides' => [
    'delete' => true,
    'update' => true,
],
```

The delete adapter preserves Lighthouse's lookup, transactions, authorization, and model events, then returns model identifiers instead of deleted models. Missing nullable results remain null. Declare scalar result types and set the model explicitly:

```graphql
type Mutation {
    deleteEntry(id: SerialID! @whereKey): SerialID
        @delete(model: "Entry")
    deleteEntries(ids: [SerialID!]! @whereKey): [SerialID!]!
        @delete(model: "Entry")
}
```

The update adapter preserves native writes and ensures the model's mapped `updated` event is dispatched once after an unchanged or relation-only save too. This is useful when application completion events must occur even if no scalar attribute changed. It only adds a dispatch when a mapped `updated` event exists, `wasChanged()` is false, and no requested attributes remain dirty. A dirty scalar save vetoed by a model listener does not produce a synthetic completion event. Ordinary changed writes retain Laravel's native event. The fallback runs after nested work and inside Lighthouse's native mutation transaction, before the model is refreshed. A synchronous mapped listener failure therefore rolls back that mutation when `lighthouse.transactional_mutations` is enabled. Disabling that native setting also disables this rollback boundary. Nested argument-level `@update` behavior is unchanged. Mapped event classes retain their own Laravel after-commit behavior; those listeners execute after a successful commit. Enabling it changes no-op event semantics, so opt in intentionally.

Changing directive registration requires rebuilding Lighthouse's schema cache and restarting retained workers. The application can also register its own directive replacements explicitly through Lighthouse's normal mechanisms.

## Translations

Package-authored public errors use English translation keys in `lang/en/messages.php` under `lighthouse-extensions`. To override them in an application:

```sh
php artisan vendor:publish --tag=lighthouse-extensions-translations
```

Place another locale at `lang/vendor/lighthouse-extensions/ru/messages.php`, preserving the keys and placeholders. The package ships English only. Messages are translated when an error occurs, so a retained schema does not capture the first request's locale. GraphQL/Lighthouse's own standard errors keep their upstream behavior. Developer-facing schema-definition and invalid-resolver errors remain English.

The generated `@dateTime` argument descriptions use `lang/en/schema.php`, with keys
`lighthouse-extensions::schema.date_time.arguments.time_zone` and
`lighthouse-extensions::schema.date_time.arguments.format`. Override them in
`lang/vendor/lighthouse-extensions/<locale>/schema.php`, keeping the same nested structure.
The translations publish tag above includes this file. Existing authored argument descriptions remain unchanged.

Descriptions are translated once while building the schema, using Laravel's active locale and configured fallback locale.
Set the intended documentation locale before building the schema cache. After changing translations or that locale,
rebuild the schema cache and reload persistent workers; changing the request locale does not change a retained schema.

## Retained Laravel workers

No Octane dependency is required. An application retaining a Lighthouse schema must retain its package preparer for the same lifetime. For Octane, add these services to `config/octane.php` `warm`, alongside the default services:

```php
Nuwave\Lighthouse\Schema\SchemaBuilder::class,
Noldors\LighthouseExtensions\Support\PrepareFieldResolvers::class,
Nuwave\Lighthouse\Execution\ErrorPool::class,
Illuminate\Contracts\Auth\Access\Gate::class,
```

Lighthouse retains resolver and directive instances inside its schema. Keep their entire constructor dependency graphs independent of request state. Resolve current request data through resolver arguments or `GraphQLContext`, and obtain request-scoped services inside invocation when required. Marking an indirectly captured dependency `scoped` does not replace a reference already held by a retained resolver.

The native error pool is shared with retained CRUD directives and is cleared by Lighthouse after normal execution. Octane updates the warmed Gate's container for each request, allowing policy dependencies to resolve from the current request. When using `noldors/lighthouse-idempotency` too, also warm its preparer; it registers its wrapper outside the lock wrapper regardless of provider order. Reload workers after schema, directive, or registration changes. Applications with custom execution listeners must preserve native error cleanup even when their listeners fail.

## Development

The test suite uses Orchestra Testbench and synthetic package-owned models/schemas. Use the package Docker environment and the scripts declared in `composer.json`. The PostgreSQL test database is isolated from application databases. Run the configured format, static-analysis, and PHPUnit checks before publishing. No repository or Packagist publication is performed by this package's installation scripts.

See [the development and release workflow](https://github.com/noldor/lighthouse-extensions/blob/master/.github/RELEASING.md) for verification, versioning and Packagist setup.
