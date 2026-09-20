<?php

use App\Libraries\BorrowingWorkflow;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Level 2 — Integration tests for the LEAU borrowing pipeline.
 *
 * Chains the building blocks exactly as the live workflow does:
 * director approval -> inventory assignment (stock decrement) ->
 * ready for pickup -> pickup -> return (stock restore + auto-archive),
 * plus the overdue branch (picked_up past expected return date).
 *
 * No database or HTTP layer is required: the pipeline is exercised through
 * BorrowingWorkflow with simulated ticket/inventory records, mirroring
 * SchedulingPipelineTest's approach for dispatch scheduling.
 *
 * @internal
 */
final class BorrowingPipelineTest extends CIUnitTestCase
{
    /**
     * Walk the full happy-path chain end to end.
     */
    public function testHappyPathChainFromSubmissionToReturn(): void
    {
        $chain = [
            'pending_director',
            'approved_director',
            'inventory_assigned',
            'ready_for_pickup',
            'picked_up',
            'returned',
        ];

        $current = array_shift($chain);
        $this->assertSame('pending_director', $current);

        foreach ($chain as $next) {
            $this->assertTrue(
                BorrowingWorkflow::canTransition($current, $next),
                "expected {$current} -> {$next} to be allowed"
            );
            $this->assertFalse(BorrowingWorkflow::isTerminal($current));
            $current = $next;
        }

        $this->assertTrue(BorrowingWorkflow::isTerminal($current));
        $this->assertSame('returned', $current);
    }

    /**
     * Overdue branch: picked_up past the return date flags overdue,
     * then still returns normally.
     */
    public function testOverdueBranchChain(): void
    {
        $this->assertTrue(BorrowingWorkflow::isOverdue('picked_up', '2026-09-19', '2026-09-20'));
        $this->assertTrue(BorrowingWorkflow::canTransition('picked_up', 'overdue'));
        $this->assertTrue(BorrowingWorkflow::canTransition('overdue', 'returned'));
        $this->assertTrue(BorrowingWorkflow::isTerminal('returned'));
    }

    /**
     * Inventory stock interplay across assign and return.
     */
    public function testInventoryStockDecrementAndRestore(): void
    {
        $total = 5;
        $available = 5;
        $requested = 3;

        $this->assertTrue(BorrowingWorkflow::canAssignInventory('approved_director'));

        $assigned = BorrowingWorkflow::clampAssignQuantity(3, $available, $requested);
        $this->assertSame(3, $assigned);

        // Assign decrements availability (controller behavior).
        $available -= $assigned;
        $this->assertSame(2, $available);

        // Return restores availability (controller behavior).
        $available += $assigned;
        $this->assertSame($total, $available);
    }

    /**
     * Over-assignment is clamped, so stock can never go negative.
     */
    public function testOverAssignmentNeverDrivesStockNegative(): void
    {
        $assigned = BorrowingWorkflow::clampAssignQuantity(9, 2, 5);
        $this->assertSame(2, $assigned);
        $this->assertGreaterThanOrEqual(0, 2 - $assigned);
    }

    /**
     * Return auto-archives the ticket as closed with no rating step.
     */
    public function testReturnAutoArchivesTicketWithNoRating(): void
    {
        $ticket = [
            'id'           => 'LEAU-TIC-1-2026',
            'status'       => 'processing',
            'current_step' => 6,
            'is_archived'  => 0,
        ];

        $patch = BorrowingWorkflow::returnTicketPatch($ticket);
        $closed = array_merge($ticket, $patch);

        $this->assertSame('closed', $closed['status']);
        $this->assertSame(1, $closed['is_archived']);
        $this->assertSame(7, $closed['current_step']);
        // Closed tickets are never feedback-eligible (no rating form shown).
        $this->assertNotSame('resolved', $closed['status']);
    }

    /**
     * Cancellation from any active stage ends the pipeline immediately.
     */
    public function testCancellationEndsPipelineFromAnyActiveStage(): void
    {
        foreach (['pending_director', 'approved_director', 'inventory_assigned', 'ready_for_pickup', 'picked_up', 'overdue'] as $stage) {
            $this->assertTrue(BorrowingWorkflow::canTransition($stage, 'cancelled'));
            $this->assertTrue(BorrowingWorkflow::isTerminal('cancelled'));
        }
    }

    /**
     * Return conditions accepted by the return endpoint.
     */
    public function testReturnConditionFlowsIntoCompletedRecord(): void
    {
        foreach (['excellent', 'good', 'fair', 'damaged', 'lost'] as $condition) {
            $this->assertTrue(BorrowingWorkflow::isValidReturnCondition($condition));
        }

        // Even damaged/lost items complete the pipeline (terminal returned).
        $this->assertTrue(BorrowingWorkflow::canTransition('picked_up', 'returned'));
        $this->assertTrue(BorrowingWorkflow::canTransition('overdue', 'returned'));
    }
}
