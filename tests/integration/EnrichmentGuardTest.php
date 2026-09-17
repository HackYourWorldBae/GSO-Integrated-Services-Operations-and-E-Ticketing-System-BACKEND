<?php

use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Level 2 — Integration tests for the ticket enrichment helpers.
 *
 * Verifies the TicketEnrichmentTrait methods shared by TicketController
 * and TicketQueueController behave safely together: the table allowlist
 * in buildDetailMap fails closed without ever touching the database,
 * enrichTickets short-circuits empty input, and formatServicesList
 * normalizes service names for slips and reports.
 *
 * Database-backed happy paths are covered by deployment smoke checks;
 * these tests pin the guard rails with a never-queried connection mock.
 *
 * @internal
 */
final class EnrichmentGuardTest extends CIUnitTestCase
{
    private object $harness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->harness = new class() {
            use \App\Controllers\API\Concerns\TicketEnrichmentTrait;

            public function expose(string $method, array $args): mixed
            {
                $ref = new ReflectionMethod($this, $method);
                $ref->setAccessible(true);

                return $ref->invokeArgs($this, $args);
            }
        };
    }

    private function neverQueriedConnection(): ConnectionInterface
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->never())->method('query');

        return $db;
    }

    public function testBuildDetailMapReturnsEmptyForEmptyIdsWithoutQuery(): void
    {
        $result = $this->harness->expose('buildDetailMap', [
            $this->neverQueriedConnection(),
            'fgmu_ticket_details',
            'ticket_id',
            [],
        ]);

        $this->assertSame([], $result);
    }

    public function testBuildDetailMapRejectsUnknownTableWithoutQuery(): void
    {
        $result = $this->harness->expose('buildDetailMap', [
            $this->neverQueriedConnection(),
            'users',
            'id',
            ['FGMU-TIC-1-2026'],
        ]);

        $this->assertSame([], $result);
    }

    public function testBuildDetailMapRejectsUnknownColumnWithoutQuery(): void
    {
        $result = $this->harness->expose('buildDetailMap', [
            $this->neverQueriedConnection(),
            'ticket_feedbacks',
            'user_id',
            ['FGMU-TIC-1-2026'],
        ]);

        $this->assertSame([], $result);
    }

    public function testBuildDetailMapRejectsSqlInjectionAttemptWithoutQuery(): void
    {
        $result = $this->harness->expose('buildDetailMap', [
            $this->neverQueriedConnection(),
            'tickets WHERE 1=1; --',
            'ticket_id',
            ['FGMU-TIC-1-2026'],
        ]);

        $this->assertSame([], $result);
    }

    public function testEnrichTicketsShortCircuitsEmptyInput(): void
    {
        $this->assertSame([], $this->harness->expose('enrichTickets', [[]]));
    }

    public function testFormatServicesListJoinsAndNormalizesNames(): void
    {
        $this->assertSame(
            'Grass Cutting, Tree Trimming',
            $this->harness->expose('formatServicesList', [['Grass Cutting Works', 'Tree Trimming']])
        );
    }

    public function testFormatServicesListKeepsLastServiceVerbatim(): void
    {
        $this->assertSame(
            'Electrical Works',
            $this->harness->expose('formatServicesList', [['Electrical Works']])
        );
    }

    public function testFormatServicesListReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame('', $this->harness->expose('formatServicesList', [[]]));
    }
}
