<?php

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for personnel username provisioning constraints and UserModel field mappings.
 *
 * @internal
 */
final class PersonnelUsernameTest extends CIUnitTestCase
{
    public function testUserModelIncludesUsernameInAllowedFields(): void
    {
        $reflection = new ReflectionClass(UserModel::class);
        $property   = $reflection->getProperty('allowedFields');
        $property->setAccessible(true);
        $allowedFields = $property->getValue($reflection->newInstanceWithoutConstructor());

        $this->assertContains('username', $allowedFields, 'UserModel allowedFields must contain username');
        $this->assertContains('email', $allowedFields, 'UserModel allowedFields must contain email');
    }

    public function testUsernameValidationRules(): void
    {
        $validUsernames = [
            'juandelacruz',
            'juan.delacruz',
            'juan_dela-cruz',
            'technician01',
            'SSU-guard.01',
        ];

        $invalidUsernames = [
            '',               // empty
            'ab',             // too short (< 3)
            'user name',      // space
            'user@name.com',  // @ symbol
            'user!name',      // exclamation mark
            'user#name',      // hash
            str_repeat('a', 51), // too long (> 50)
        ];

        $regex = '/^[a-zA-Z0-9._-]+$/';

        foreach ($validUsernames as $username) {
            $this->assertTrue(
                mb_strlen($username) >= 3 && mb_strlen($username) <= 50 && (bool) preg_match($regex, $username),
                "Username '{$username}' should be valid"
            );
        }

        foreach ($invalidUsernames as $username) {
            $isValid = mb_strlen($username) >= 3 && mb_strlen($username) <= 50 && (bool) preg_match($regex, $username);
            $this->assertFalse(
                $isValid,
                "Username '{$username}' should be rejected as invalid"
            );
        }
    }

    public function testAddUsernameMigrationExistsAndIsValid(): void
    {
        $migrationFile = APPPATH . 'Database/Migrations/2026_10_11_000001_AddUsernameToUsersTable.php';
        $this->assertFileExists($migrationFile, 'Migration file for adding username must exist');

        require_once $migrationFile;
        $this->assertTrue(
            class_exists('App\Database\Migrations\AddUsernameToUsersTable'),
            'AddUsernameToUsersTable migration class must exist'
        );
    }
}
