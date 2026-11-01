<?php

declare(strict_types = 1);

return [
    'uuid' => 'The value must be a UUID.',
    'uuid_string' => 'The UUID must be provided as a string.',
    'serial_id' => 'The identifier must be a positive integer within the supported range.',
    'serial_id_type' => 'The identifier must be provided as an integer or an integer string.',
    'date_string' => 'Date cannot be created from a non-string value.',
    'date_format' => 'Date format must match :format',
    'datetime_string' => 'DateTime cannot be created from a non-string value.',
    'datetime_format' => 'DateTime format must match :format',
    'invalid_timezone' => 'The timezone is invalid.',
];
