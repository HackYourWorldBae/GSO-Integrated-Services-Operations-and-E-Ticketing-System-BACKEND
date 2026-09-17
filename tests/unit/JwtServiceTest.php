<?php

use App\Libraries\JwtService;
use CodeIgniter\Test\CIUnitTestCase;
use Firebase\JWT\JWT;

/**
 * Level 1 — Unit tests for JWT issue/validate operations.
 *
 * Covers every public method of App\Libraries\JwtService, which secures
 * all protected API operations (auth, ticket queues, dispatch, personnel).
 *
 * @internal
 */
final class JwtServiceTest extends CIUnitTestCase
{
    private JwtService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the suite hermetic: fall back to a test-only secret when the
        // deployment .env value is unavailable in this environment.
        if (empty(env('JWT_SECRET'))) {
            $_SERVER['JWT_SECRET'] = 'gso-test-only-jwt-secret-0123456789abcdef';
            putenv('JWT_SECRET=gso-test-only-jwt-secret-0123456789abcdef');
        }

        $this->service = new JwtService();
    }

    public function testConstructorLoadsConfiguredLifetimes(): void
    {
        $this->assertGreaterThan(0, $this->service->getExpiresIn());
    }

    public function testAccessTokenRoundTripPreservesIdentity(): void
    {
        $token = $this->service->generateAccessToken([
            'id'      => 'user-123',
            'role'    => 'admin',
            'unit_id' => 1,
        ]);

        $this->assertIsString($token);
        $this->assertSame(2, substr_count($token, '.')); // header.payload.signature

        $result = $this->service->validateToken($token);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
        $this->assertSame('access', $result['data']['type']);
        $this->assertSame('user-123', $result['data']['data']['id']);
        $this->assertSame('admin', $result['data']['data']['role']);
        $this->assertSame(1, $result['data']['data']['unit_id']);
        $this->assertArrayHasKey('iss', $result['data']);
        $this->assertArrayHasKey('exp', $result['data']);
    }

    public function testRefreshTokenHasRefreshTypeAndLongerLifetime(): void
    {
        $access  = $this->service->generateAccessToken(['id' => 'user-1']);
        $refresh = $this->service->generateRefreshToken(['id' => 'user-1']);

        $accessResult  = $this->service->validateToken($access);
        $refreshResult = $this->service->validateToken($refresh);

        $this->assertTrue($accessResult['valid']);
        $this->assertTrue($refreshResult['valid']);
        $this->assertSame('refresh', $refreshResult['data']['type']);
        $this->assertGreaterThan(
            $accessResult['data']['exp'],
            $refreshResult['data']['exp']
        );
    }

    public function testExpiredTokenIsRejectedWithExpiryError(): void
    {
        $secret = env('JWT_SECRET');
        $now    = time();

        $expired = JWT::encode([
            'iss'  => 'test',
            'iat'  => $now - 7200,
            'exp'  => $now - 3600,
            'type' => 'access',
            'data' => ['id' => 'user-1'],
        ], $secret, 'HS256');

        $result = $this->service->validateToken($expired);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['data']);
        $this->assertSame('Token has expired.', $result['error']);
    }

    public function testWrongSecretTokenIsRejectedWithSignatureError(): void
    {
        $now = time();

        $forged = JWT::encode([
            'iss'  => 'test',
            'iat'  => $now,
            'exp'  => $now + 3600,
            'type' => 'access',
            'data' => ['id' => 'user-1'],
        ], 'a-completely-different-secret-value-00', 'HS256');

        $result = $this->service->validateToken($forged);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['data']);
        $this->assertSame('Token signature is invalid.', $result['error']);
    }

    public function testMalformedTokenIsRejected(): void
    {
        $result = $this->service->validateToken('not-a-jwt-token');

        $this->assertFalse($result['valid']);
        $this->assertNull($result['data']);
        $this->assertNotEmpty($result['error']);
    }

    public function testExtractBearerTokenParsesAuthorizationHeader(): void
    {
        $this->assertSame(
            'abc.def.ghi',
            $this->service->extractBearerToken('Bearer abc.def.ghi')
        );
    }

    public function testExtractBearerTokenRejectsMalformedHeaders(): void
    {
        $this->assertNull($this->service->extractBearerToken(''));
        $this->assertNull($this->service->extractBearerToken('Token abc.def'));
        $this->assertNull($this->service->extractBearerToken('bearer abc.def'));
        $this->assertNull($this->service->extractBearerToken('Bearer '));
        $this->assertNull($this->service->extractBearerToken('Bearer    '));
    }
}
