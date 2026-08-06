<?php

declare(strict_types=1);

namespace Feedple\Sdk\Core;

/**
 * Represents the authenticated identity of the tenant's application.
 *
 * Mirrors Python's @dataclass Identity:
 *
 *   @dataclass
 *   class Identity:
 *       name: Optional[str]
 *       allowed_tables: List[str] = field(default_factory=list)
 *       all_tables: bool = False
 */
class Identity
{
    /**
     * @param  string|null    $name          Human-readable name for this identity (e.g. "admin", "read-only").
     * @param  string[]       $allowedTables Explicit list of tables this identity may access.
     *                                       Ignored when $allTables is true.
     * @param  bool           $allTables     When true, the identity can access every table in the database.
     */
    public function __construct(
        public readonly ?string $name,
        public readonly array   $allowedTables = [],
        public readonly bool    $allTables     = false,
    ) {
    }
}
