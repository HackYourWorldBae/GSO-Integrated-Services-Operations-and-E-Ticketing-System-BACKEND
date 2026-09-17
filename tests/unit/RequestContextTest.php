<?php

use App\Libraries\RequestContext;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Level 1 — Unit tests for the per-request JWT payload registry.
 *
 * Covers App\Libraries\RequestContext, the bridge between JwtAuthFilter
 * (writer) and BaseController::currentUser* accessors (readers).
 *
 * @internal
 */
final class RequestContextTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::clear();
    }

    protected function tearDown(): void
    {
        RequestContext::clear();
        parent::tearDown();
    }

    public function testDefaultsToEmptyArrayWhenUnauthenticated(): void
    {
        $this->assertSame([], RequestContext::getJwtPayload());
    }

    public function testStoresAndRetrievesJwtPayload(): void
    {
        $payload = ['id' => 'user-1', 'role' => 'admin', 'unit_id' => 1];

        RequestContext::setJwtPayload($payload);

        $this->assertSame($payload, RequestContext::getJwtPayload());
    }

    public function testOverwritesPreviousPayload(): void
    {
        RequestContext::setJwtPayload(['id' => 'user-1', 'role' => 'admin']);
        RequestContext::setJwtPayload(['id' => 'user-2', 'role' => 'director']);

        $this->assertSame('director', RequestContext::getJwtPayload()['role']);
    }

    public function testClearResetsToEmptyArray(): void
    {
        RequestContext::setJwtPayload(['id' => 'user-1', 'role' => 'admin']);
        RequestContext::clear();

        $this->assertSame([], RequestContext::getJwtPayload());
    }
}
