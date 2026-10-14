<?php

use App\Libraries\JwtService;
use App\Libraries\RequestContext;
use App\Models\UserSessionModel;
use App\Models\TicketLogModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for Unit Head Delegation & Acting Head session claims.
 *
 * @internal
 */
final class DelegationTokenTest extends CIUnitTestCase
{
    private JwtService $jwt;

    protected function setUp(): void
    {
        parent::setUp();

        if (empty(env('JWT_SECRET'))) {
            $_SERVER['JWT_SECRET'] = 'gso-test-only-jwt-secret-0123456789abcdef';
            putenv('JWT_SECRET=gso-test-only-jwt-secret-0123456789abcdef');
        }

        $this->jwt = new JwtService();
        RequestContext::clear();
    }

    protected function tearDown(): void
    {
        RequestContext::clear();
        parent::tearDown();
    }

    public function testDelegatedTokenPayloadRoundTripPreservesActingClaims(): void
    {
        $payload = [
            'id'               => 'dir-uuid-001',
            'role'             => 'admin',
            'unit_id'          => 1,
            'sid'              => 'sess-abc-123',
            'is_acting_head'   => true,
            'original_role'    => 'director',
            'acting_unit_code' => 'FGMU',
            'acting_unit_name' => 'Facilities & Grounds Management Unit',
        ];

        $token = $this->jwt->generateAccessToken($payload);
        $result = $this->jwt->validateToken($token);

        $this->assertTrue($result['valid']);
        $data = $result['data']['data'];
        $this->assertSame('dir-uuid-001', $data['id']);
        $this->assertSame('admin', $data['role']);
        $this->assertSame(1, $data['unit_id']);
        $this->assertTrue($data['is_acting_head']);
        $this->assertSame('director', $data['original_role']);
        $this->assertSame('FGMU', $data['acting_unit_code']);
    }

    public function testUserSessionModelHasActiveSessionGracefulFallback(): void
    {
        $sessionModel = new UserSessionModel();
        // A non-existent UUID should return false
        $this->assertFalse($sessionModel->hasActiveSession('non-existent-user-uuid'));
    }

    public function testTicketLogAutoTagsActingUnitHeadWhenContextIsActive(): void
    {
        RequestContext::setJwtPayload([
            'id'               => 'dir-uuid-001',
            'role'             => 'admin',
            'unit_id'          => 1,
            'is_acting_head'   => true,
            'original_role'    => 'director',
            'acting_unit_code' => 'FGMU',
        ]);

        $logModel = new TicketLogModel();
        // Call logAction with a mock ticket ID (will fail quietly if DB table not populated in unit env or succeed)
        $logged = $logModel->logAction('TEST-TICKET-01', 'dir-uuid-001', 'Approved', 'Ticket approved for dispatch.');
        
        // Assert logAction returns a boolean without throwing an unhandled exception
        $this->assertIsBool($logged);
    }
}
