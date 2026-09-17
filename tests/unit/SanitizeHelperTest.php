<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Level 1 — Unit tests for input sanitization helpers.
 *
 * Covers every function in app/Helpers/sanitize_helper.php, which guards
 * all ticket intake, dispatch, and profile operations against XSS payloads.
 *
 * @internal
 */
final class SanitizeHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('sanitize');
    }

    public function testSanitizeStringStripsTagsAndTrims(): void
    {
        $this->assertSame('Hello', sanitize_string('  <b>Hello</b>  '));
        $this->assertSame('alert(1)', sanitize_string('<script>alert(1)</script>'));
    }

    public function testSanitizeStringHandlesNullAndEmpty(): void
    {
        $this->assertSame('', sanitize_string(null));
        $this->assertSame('', sanitize_string(''));
        $this->assertSame('', sanitize_string('   '));
    }

    public function testSanitizeStringPreservesPlainText(): void
    {
        $this->assertSame('FGMU-TIC-42-2026', sanitize_string('FGMU-TIC-42-2026'));
    }

    public function testSanitizeArrayRecursesIntoNestedStructures(): void
    {
        $result = sanitize_array([
            'title'   => '  <i>Repair</i> ',
            'nested'  => ['note' => '<script>x</script>clean'],
            'count'   => 3,
            'active'  => true,
            'price'   => 12.5,
        ]);

        $this->assertSame('Repair', $result['title']);
        $this->assertSame('xclean', $result['nested']['note']);
        $this->assertSame(3, $result['count']);
        $this->assertTrue($result['active']);
        $this->assertSame(12.5, $result['price']);
    }

    public function testGenerateUuidMatchesVersion4Format(): void
    {
        $uuid = generate_uuid();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    public function testGenerateUuidProducesUniqueValues(): void
    {
        $this->assertNotSame(generate_uuid(), generate_uuid());
    }

    public function testGenerateTicketIdFormatsUnitSequenceAndYear(): void
    {
        $year = date('Y');

        $this->assertSame("FGMU-TIC-42-{$year}", generate_ticket_id('FGMU', 42));
        $this->assertSame("LEAU-TIC-1-{$year}", generate_ticket_id('leau', 1));
        $this->assertSame("SSU-TIC-7-{$year}", generate_ticket_id('SSU', 7));
    }

    public function testApiResponseEnvelopeShape(): void
    {
        $response = api_response(true, 'Worker assigned successfully.', ['ticket_id' => 'FGMU-TIC-1-2026'], 201);

        $this->assertSame([
            'status'  => true,
            'code'    => 201,
            'message' => 'Worker assigned successfully.',
            'data'    => ['ticket_id' => 'FGMU-TIC-1-2026'],
        ], $response);
    }

    public function testApiResponseDefaults(): void
    {
        $response = api_response(false, 'Validation failed.');

        $this->assertFalse($response['status']);
        $this->assertSame(200, $response['code']);
        $this->assertSame([], $response['data']);
    }
}
