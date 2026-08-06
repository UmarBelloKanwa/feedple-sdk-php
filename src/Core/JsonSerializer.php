<?php

declare(strict_types=1);

namespace Feedple\Sdk\Core;

use DateTime;
use DateTimeInterface;

/**
 * Safe JSON serializer that handles types which json_encode() cannot handle
 * natively: DateTimeInterface, objects with public properties, and nested
 * complex values.
 *
 * Mirrors Python's SafeJSONEncoder which handles UUID, datetime, and
 * SQLAlchemy Row/Mapping objects.
 */
class JsonSerializer
{
    /**
     * Encode a value to a JSON string.
     *
     * @param  mixed $data
     * @return string
     * @throws \JsonException on encoding error
     */
    public static function encode(mixed $data): string
    {
        return json_encode(
            self::normalize($data),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Recursively normalize a value so json_encode() can handle it safely.
     *
     * Mirrors Python's SafeJSONEncoder.default():
     *  - DateTimeInterface → ISO 8601 string  (mirrors datetime/date handling)
     *  - Objects with toArray() / jsonSerialize() → call those
     *  - Generic objects → extract public properties  (mirrors __dict__ fallback)
     *  - Arrays → recurse
     *  - Scalars / null → pass through
     *
     * @param  mixed $value
     * @return mixed
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            // Mirrors: if isinstance(obj, (datetime, date)): return obj.isoformat()
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof \JsonSerializable) {
            return self::normalize($value->jsonSerialize());
        }

        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                return self::normalize($value->toArray());
            }

            // Mirrors: return {k: v for k, v in obj.__dict__.items() if not k.startswith('_')}
            $props = get_object_vars($value);
            $result = [];
            foreach ($props as $key => $val) {
                // Skip private-convention properties (starting with underscore)
                if (str_starts_with($key, '_')) {
                    continue;
                }
                $result[$key] = self::normalize($val);
            }
            return $result;
        }

        if (is_array($value)) {
            return array_map([self::class, 'normalize'], $value);
        }

        // Scalars (int, float, string, bool, null) pass through untouched
        return $value;
    }

    /**
     * Decode a JSON string into a PHP array.
     *
     * @param  string $json
     * @return array<string, mixed>
     * @throws \JsonException on decoding error
     */
    public static function decode(string $json): array
    {
        return json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
    }
}
