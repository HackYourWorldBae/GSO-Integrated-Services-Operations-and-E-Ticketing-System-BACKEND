<?php

use App\Libraries\JwtService;
use App\Libraries\RequestContext;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Level 2 — Integration tests for the request authentication pipeline.
 *
 * Verifies JwtService and RequestContext work together exactly as the
 * JwtAuthFilter -> RequestContext -> BaseController chain uses them:
 * issue -> Bearer extract -> validate -> store -> resolve identity,
 * including cross-request isolation and tamper rejection.
 *
 * @internal
 */
final class AuthPipelineTest extends CIUnitTestCase
{
    private JwtService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (empty(env('JWT_SECRET'))) {
            $_SERVER['JWT_SECRET'] = 'gso-test-only-jwt-secret-0123456789abcdef';
            putenv('JWT_SECRET=gso-test-only-jwt-secret-0123456789abcdef');
        }

        $this->service = new JwtService();
        RequestContext::clear();
    }

    protected function tearDown(): void
    {
        RequestContext::clear();
        parent::tearDown();
    }

    /**
     * Simulate one authenticated request lifecycle for a Unit Head.
     */
    public function testAdminRequestLifecycleResolvesIdentity(): void
    {
        // 1. Login issues a token embedding the identity.
        $token = $this->service->generateAccessToken([
            'id'      => 'admin-uuid-1',
            'role'    => 'admin',
            'unit_id' => 1,
        ]);

        // 2. Filter extracts the Bearer token from the header.
        $extracted = $this->service->extractBearerToken('Bearer ' . $token);
        $this->assertSame($token, $extracted);

        // 3. Filter validates and stores the payload for the request.
        $result = $this->service->validateToken($extracted);
        $this->assertTrue($result['valid']);
        RequestContext::setJwtPayload($result['data']['data']);

        // 4. Controller resolves the identity (mirrors currentUser*()).
        $payload = RequestContext::getJwtPayload();
        $this->assertSame('admin-uuid-1', $payload['id']);
        $this->assertSame('admin', $payload['role']);
        $this->assertSame(1, $payload['unit_id']);
    }

    /**
     * Simulate a requestor lifecycle and assert unit scoping data survives.
     */
    public function testRequestorLifecyclePreservesRoleForGuards(): void
    {
        $token   = $this->service->generateAccessToken(['id' => 'stu-1', 'role' => 'student']);
        $result  = $this->service->validateToken($token);
        RequestContext::setJwtPayload($result['data']['data']);

        $payload = RequestContext::getJwtPayload();
        $this->assertSame('student', $payload['role']);
        $this->assertArrayNotHasKey('unit_id', $payload);
    }

    public function testConsecutiveRequestsDoNotLeakIdentity(): void
    {
        $first  = $this->service->generateAccessToken(['id' => 'admin-1', 'role' => 'admin']);
        $second = $this->service->generateAccessToken(['id' => 'stu-9', 'role' => 'student']);

        $r1 = $this->service->validateToken($first);
        RequestContext::setJwtPayload($r1['data']['data']);
        $this->assertSame('admin', RequestContext::getJwtPayload()['role']);

        // Next request lifecycle starts clean (filter runs before guards).
        RequestContext::clear();
        $this->assertSame([], RequestContext::getJwtPayload());

        $r2 = $this->service->validateToken($second);
        RequestContext::setJwtPayload($r2['data']['data']);
        $this->assertSame('student', RequestContext::getJwtPayload()['role']);
    }

    public function testTamperedTokenNeverReachesRequestContext(): void
    {
        $token   = $this->service->generateAccessToken(['id' => 'admin-1', 'role' => 'admin']);
        $tampered = substr($token, 0, -2) . 'xx';

        $result = $this->service->validateToken($tampered);

        $this->assertFalse($result['valid']);
        // Nothing stored: downstream guards see an unauthenticated request.
        $this->assertSame([], RequestContext::getJwtPayload());
    }

    public function testMissingAuthorizationHeaderYieldsUnauthenticatedContext(): void
    {
        $this->assertNull($this->service->extractBearerToken(''));
        $this->assertSame([], RequestContext::getJwtPayload());
    }
}
