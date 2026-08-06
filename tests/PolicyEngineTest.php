<?php

declare(strict_types=1);

namespace Feedple\Sdk\Tests;

use Feedple\Sdk\Core\Identity;
use Feedple\Sdk\Core\PolicyEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Identity and PolicyEngine.
 *
 * Mirrors the RBAC behaviour tested implicitly in Python's test_sdk.py
 * and the validate_ir_access logic in policy.py.
 */
class PolicyEngineTest extends TestCase
{
    // ── Identity construction ─────────────────────────────────────────────

    public function testIdentityStoresName(): void
    {
        $identity = new Identity(name: 'admin');
        $this->assertSame('admin', $identity->name);
    }

    public function testIdentityDefaultsToNoTablesAndNotAllTables(): void
    {
        $identity = new Identity(name: 'guest');
        $this->assertSame([], $identity->allowedTables);
        $this->assertFalse($identity->allTables);
    }

    public function testIdentityAcceptsNullName(): void
    {
        $identity = new Identity(name: null, allTables: true);
        $this->assertNull($identity->name);
        $this->assertTrue($identity->allTables);
    }

    // ── canAccessTable ────────────────────────────────────────────────────

    public function testAllTablesGrantsAccessToAnyTable(): void
    {
        $policy = new PolicyEngine(new Identity(name: 'admin', allTables: true));
        $this->assertTrue($policy->canAccessTable('users'));
        $this->assertTrue($policy->canAccessTable('orders'));
        $this->assertTrue($policy->canAccessTable('secret_financials'));
    }

    public function testAllowedTablesRestrictsAccess(): void
    {
        $policy = new PolicyEngine(new Identity(
            name: 'read-only',
            allowedTables: ['users', 'products'],
        ));
        $this->assertTrue($policy->canAccessTable('users'));
        $this->assertTrue($policy->canAccessTable('products'));
        $this->assertFalse($policy->canAccessTable('orders'));
        $this->assertFalse($policy->canAccessTable('invoices'));
    }

    public function testEmptyAllowedTablesDeniesEverything(): void
    {
        $policy = new PolicyEngine(new Identity(name: 'locked'));
        $this->assertFalse($policy->canAccessTable('users'));
        $this->assertFalse($policy->canAccessTable('anything'));
    }

    // ── validateIrAccess ─────────────────────────────────────────────────

    public function testValidateIrAccessPassesWhenAllTablesEnabled(): void
    {
        $policy = new PolicyEngine(new Identity(name: 'admin', allTables: true));
        // Should not throw
        $policy->validateIrAccess(['table' => 'orders', 'joins' => [['table' => 'users']]]);
        $this->addToAssertionCount(1);
    }

    public function testValidateIrAccessPassesForAllowedTable(): void
    {
        $policy = new PolicyEngine(new Identity(
            name: 'reader',
            allowedTables: ['orders', 'users'],
        ));
        $policy->validateIrAccess(['table' => 'orders', 'joins' => [['table' => 'users']]]);
        $this->addToAssertionCount(1);
    }

    public function testValidateIrAccessThrowsForDeniedBaseTable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Access denied to table: invoices/');

        $policy = new PolicyEngine(new Identity(
            name: 'reader',
            allowedTables: ['orders'],
        ));
        $policy->validateIrAccess(['table' => 'invoices']);
    }

    public function testValidateIrAccessThrowsForDeniedJoinTable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Access denied to table: secret_data/');

        $policy = new PolicyEngine(new Identity(
            name: 'reader',
            allowedTables: ['orders'],
        ));
        $policy->validateIrAccess([
            'table' => 'orders',
            'joins' => [['table' => 'secret_data']],
        ]);
    }

    public function testValidateIrAccessWithNoJoinsKey(): void
    {
        $policy = new PolicyEngine(new Identity(
            name: 'reader',
            allowedTables: ['orders'],
        ));
        // Should not throw — no joins key means no extra tables to check
        $policy->validateIrAccess(['table' => 'orders']);
        $this->addToAssertionCount(1);
    }
}
