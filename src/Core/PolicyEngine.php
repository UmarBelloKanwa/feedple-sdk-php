<?php

declare(strict_types=1);

namespace Feedple\Sdk\Core;

/**
 * Row-based access control engine.
 *
 * Mirrors Python's PolicyEngine (core/policy.py):
 *
 *   class PolicyEngine:
 *       def can_access_table(self, table: str) -> bool: ...
 *       def validate_ir_access(self, ir: dict) -> None: ...
 *
 * The Identity dataclass is defined in Identity.php (same namespace).
 */
class PolicyEngine
{
    public function __construct(
        public readonly Identity $identity,
    ) {
    }

    // ── RBAC ─────────────────────────────────────────────────────────────

    /**
     * Returns true if the identity may access the given table.
     *
     * Mirrors: can_access_table(self, table: str) -> bool
     */
    public function canAccessTable(string $table): bool
    {
        if ($this->identity->allTables) {
            return true;
        }
        return in_array($table, $this->identity->allowedTables, strict: true);
    }

    /**
     * Validates that every table referenced in the IR is permitted.
     * Throws \RuntimeException if any table is denied.
     *
     * Mirrors: validate_ir_access(self, ir: dict) -> None
     *
     * @param  array<string, mixed> $ir
     * @throws \RuntimeException
     */
    public function validateIrAccess(array $ir): void
    {
        if ($this->identity->allTables) {
            return;
        }

        // Collect base table + all joined tables (mirrors Python implementation)
        $tables = [$ir['table'] ?? ''];
        foreach (($ir['joins'] ?? []) as $join) {
            $tables[] = $join['table'] ?? '';
        }

        foreach ($tables as $table) {
            if ($table !== '' && !$this->canAccessTable($table)) {
                throw new \RuntimeException("Access denied to table: {$table}");
            }
        }
    }
}
