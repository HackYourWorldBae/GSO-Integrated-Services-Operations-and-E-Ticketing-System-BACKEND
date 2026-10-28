<?php

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests verifying UserModel accurately manages ticket request counts,
 * detects schema capabilities without assuming a nonexistent tickets.created_at,
 * and enriches user payloads safely.
 *
 * @internal
 */
final class UserRequestCountTest extends CIUnitTestCase
{
    public function testEnrichUsersPreservesRequestCountAndRecentRequestActivity(): void
    {
        $reflection = new ReflectionClass(UserModel::class);
        $method = $reflection->getMethod('enrichUsersWithPresenceAndInactivity');
        $method->setAccessible(true);

        $model = $reflection->newInstanceWithoutConstructor();

        $rawUsers = [
            [
                'id' => 'user-1',
                'first_name' => 'Alice',
                'role' => 'client',
                'status' => 'Active',
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 year')),
                'request_count' => 7,
                'last_request_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
            ],
            [
                'id' => 'user-2',
                'first_name' => 'Bob',
                'role' => 'client',
                'status' => 'Active',
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 year')),
                'request_count' => 0,
                'last_request_at' => null,
            ],
        ];

        $enriched = $method->invoke($model, $rawUsers);

        $this->assertCount(2, $enriched);
        $this->assertSame(7, $enriched[0]['request_count']);
        $this->assertFalse($enriched[0]['is_inactive_candidate'], 'User with requests within 6 months must not be inactive candidate');

        $this->assertSame(0, $enriched[1]['request_count']);
        $this->assertTrue($enriched[1]['is_inactive_candidate'], 'User with 0 requests and no logins in 1 year should be inactive candidate');
    }

    public function testUserModelDoesNotContainHardcodedTicketsCreatedAt(): void
    {
        $filePath = ROOTPATH . 'app/Models/UserModel.php';
        $content = file_get_contents($filePath);

        $this->assertNotFalse($content, 'UserModel.php must exist and be readable');

        // Verify there is no SELECT MAX(created_at) FROM tickets or tickets.created_at
        $this->assertDoesNotMatchRegularExpression(
            '/tickets\.created_at/i',
            $content,
            'UserModel.php must not reference nonexistent tickets.created_at'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/SELECT\s+MAX\(created_at\)\s+FROM\s+tickets/i',
            $content,
            'UserModel.php must not query MAX(created_at) on tickets'
        );
    }
}
