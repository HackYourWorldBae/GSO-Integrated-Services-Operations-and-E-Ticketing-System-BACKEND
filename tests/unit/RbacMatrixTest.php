<?php

use App\Models\RolePermissionModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Level 1 — Unit tests for the RBAC capability matrix.
 *
 * Covers App\Models\RolePermissionModel::hasPermission(),
 * getPermissionsForRole() and getFullMatrix(), which enforce
 * Role-Based Access Control for every protected operation.
 *
 * The model is instantiated without its database constructor because the
 * matrix is intentionally hardcoded (retired role_permissions table) and
 * all methods under test are pure in-memory checks.
 *
 * @internal
 */
final class RbacMatrixTest extends CIUnitTestCase
{
    private RolePermissionModel $matrix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matrix = (new ReflectionClass(RolePermissionModel::class))
            ->newInstanceWithoutConstructor();
    }

    public function testSystemRolesContainExactlyFiveRolesWithoutDispatcher(): void
    {
        $this->assertSame(
            ['superadmin', 'admin', 'director', 'employee', 'student'],
            RolePermissionModel::SYSTEM_ROLES
        );
        $this->assertNotContains('dispatcher', RolePermissionModel::SYSTEM_ROLES);
        $this->assertNotContains('worker', RolePermissionModel::SYSTEM_ROLES);
    }

    public function testSuperadminHasEveryCapability(): void
    {
        foreach (RolePermissionModel::SYSTEM_FEATURES as $feature) {
            $this->assertTrue(
                $this->matrix->hasPermission('superadmin', $feature['key']),
                "superadmin should have {$feature['key']}"
            );
        }
        $this->assertTrue($this->matrix->hasPermission('superadmin', 'anything.at.all'));
    }

    public function testAdminUnitHeadHoldsFullOperationalCapabilities(): void
    {
        foreach ([
            'tickets.create', 'tickets.view_all', 'tickets.approve_decline',
            'tickets.dispatch', 'tickets.assign_worker', 'tickets.complete_work',
            'tickets.verify_close', 'personnel.manage', 'reports.view',
        ] as $feature) {
            $this->assertTrue(
                $this->matrix->hasPermission('admin', $feature),
                "admin should have {$feature}"
            );
        }
    }

    public function testAdminCannotProvisionAccountsOrControlMatrix(): void
    {
        $this->assertFalse($this->matrix->hasPermission('admin', 'users.provision'));
        $this->assertFalse($this->matrix->hasPermission('admin', 'system.matrix_control'));
    }

    public function testDirectorHasOversightCapabilitiesOnly(): void
    {
        $this->assertTrue($this->matrix->hasPermission('director', 'tickets.view_all'));
        $this->assertTrue($this->matrix->hasPermission('director', 'reports.view'));

        $this->assertFalse($this->matrix->hasPermission('director', 'tickets.dispatch'));
        $this->assertFalse($this->matrix->hasPermission('director', 'tickets.assign_worker'));
        $this->assertFalse($this->matrix->hasPermission('director', 'personnel.manage'));
        $this->assertFalse($this->matrix->hasPermission('director', 'tickets.create'));
    }

    public function testRequestorsCanCreateAndRateButNothingElse(): void
    {
        foreach (['student', 'employee'] as $role) {
            $this->assertTrue($this->matrix->hasPermission($role, 'tickets.create'));
            $this->assertTrue($this->matrix->hasPermission($role, 'tickets.rate'));

            $this->assertFalse($this->matrix->hasPermission($role, 'tickets.view_all'));
            $this->assertFalse($this->matrix->hasPermission($role, 'tickets.approve_decline'));
            $this->assertFalse($this->matrix->hasPermission($role, 'tickets.dispatch'));
            $this->assertFalse($this->matrix->hasPermission($role, 'personnel.manage'));
            $this->assertFalse($this->matrix->hasPermission($role, 'reports.view'));
        }
    }

    public function testUnknownRolesHaveNoCapabilities(): void
    {
        $this->assertFalse($this->matrix->hasPermission('dispatcher', 'tickets.dispatch'));
        $this->assertFalse($this->matrix->hasPermission('worker', 'tickets.complete_work'));
        $this->assertFalse($this->matrix->hasPermission('ghost', 'tickets.create'));
        $this->assertFalse($this->matrix->hasPermission('', 'tickets.create'));
    }

    public function testGetPermissionsForRoleMatchesHasPermission(): void
    {
        foreach (RolePermissionModel::SYSTEM_ROLES as $role) {
            $expected = [];
            foreach (RolePermissionModel::SYSTEM_FEATURES as $feature) {
                if ($this->matrix->hasPermission($role, $feature['key'])) {
                    $expected[] = $feature['key'];
                }
            }

            $this->assertSame($expected, $this->matrix->getPermissionsForRole($role));
        }
    }

    public function testGetPermissionsForSuperadminReturnsEveryFeatureKey(): void
    {
        $this->assertSame(
            array_column(RolePermissionModel::SYSTEM_FEATURES, 'key'),
            $this->matrix->getPermissionsForRole('superadmin')
        );
    }

    public function testGetFullMatrixStructure(): void
    {
        $full = $this->matrix->getFullMatrix();

        $this->assertSame(RolePermissionModel::SYSTEM_FEATURES, $full['features']);
        $this->assertSame(RolePermissionModel::SYSTEM_ROLES, $full['roles']);
        $this->assertCount(count(RolePermissionModel::SYSTEM_FEATURES), $full['matrix']);

        foreach ($full['matrix'] as $row) {
            $this->assertArrayHasKey('key', $row);
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('roles', $row);
            $this->assertSame(RolePermissionModel::SYSTEM_ROLES, array_keys($row['roles']));
        }
    }

    public function testDispatchCapabilitiesBelongSolelyToAdminHierarchy(): void
    {
        $this->assertTrue($this->matrix->hasPermission('admin', 'tickets.dispatch'));
        $this->assertTrue($this->matrix->hasPermission('admin', 'tickets.assign_worker'));
        $this->assertTrue($this->matrix->hasPermission('superadmin', 'tickets.dispatch'));

        $this->assertFalse($this->matrix->hasPermission('director', 'tickets.dispatch'));
        $this->assertFalse($this->matrix->hasPermission('employee', 'tickets.dispatch'));
        $this->assertFalse($this->matrix->hasPermission('student', 'tickets.assign_worker'));
    }
}
