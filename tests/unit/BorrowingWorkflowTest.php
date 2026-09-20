<?php

use App\Libraries\BorrowingWorkflow;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Level 1 — Unit tests for the LEAU borrowing state machine.
 *
 * Covers every rule in App\Libraries\BorrowingWorkflow, which mirrors the
 * transitions enforced by BorrowingController:
 * pending_director -> approved_director -> inventory_assigned ->
 * ready_for_pickup -> picked_up -> returned, with overdue as a simple
 * visual flag and returned/cancelled as terminal states.
 *
 * @internal
 */
final class BorrowingWorkflowTest extends CIUnitTestCase
{
    public function testStatusCatalogHasEightStates(): void
    {
        $this->assertCount(8, BorrowingWorkflow::TRANSITIONS);
        $this->assertSame(
            ['returned', 'cancelled'],
            BorrowingWorkflow::TERMINAL_STATUSES
        );
    }

    public function testTerminalStatesAreReturnedAndCancelledOnly(): void
    {
        $this->assertTrue(BorrowingWorkflow::isTerminal('returned'));
        $this->assertTrue(BorrowingWorkflow::isTerminal('cancelled'));
        $this->assertFalse(BorrowingWorkflow::isTerminal('picked_up'));
        $this->assertFalse(BorrowingWorkflow::isTerminal('overdue'));
        $this->assertFalse(BorrowingWorkflow::isTerminal('ready_for_pickup'));
    }

    public function testHappyPathTransitionsAreAllowed(): void
    {
        $this->assertTrue(BorrowingWorkflow::canTransition('pending_director', 'approved_director'));
        $this->assertTrue(BorrowingWorkflow::canTransition('approved_director', 'inventory_assigned'));
        $this->assertTrue(BorrowingWorkflow::canTransition('inventory_assigned', 'ready_for_pickup'));
        $this->assertTrue(BorrowingWorkflow::canTransition('ready_for_pickup', 'picked_up'));
        $this->assertTrue(BorrowingWorkflow::canTransition('picked_up', 'returned'));
    }

    public function testOverdueBranchTransitionsAreAllowed(): void
    {
        $this->assertTrue(BorrowingWorkflow::canTransition('picked_up', 'overdue'));
        $this->assertTrue(BorrowingWorkflow::canTransition('overdue', 'returned'));
    }

    public function testCancellationIsAllowedFromEveryNonTerminalState(): void
    {
        foreach (['pending_director', 'approved_director', 'inventory_assigned', 'ready_for_pickup', 'picked_up', 'overdue'] as $from) {
            $this->assertTrue(
                BorrowingWorkflow::canTransition($from, 'cancelled'),
                "expected {$from} -> cancelled to be allowed"
            );
        }
    }

    public function testSkippedStagesAreRejected(): void
    {
        $this->assertFalse(BorrowingWorkflow::canTransition('approved_director', 'picked_up'));
        $this->assertFalse(BorrowingWorkflow::canTransition('pending_director', 'inventory_assigned'));
        $this->assertFalse(BorrowingWorkflow::canTransition('inventory_assigned', 'picked_up'));
        $this->assertFalse(BorrowingWorkflow::canTransition('returned', 'picked_up'));
        $this->assertFalse(BorrowingWorkflow::canTransition('cancelled', 'returned'));
        $this->assertFalse(BorrowingWorkflow::canTransition('bogus', 'returned'));
    }

    public function testOverdueFlagIsSimpleDateComparison(): void
    {
        // Explicit overdue status always reads overdue.
        $this->assertTrue(BorrowingWorkflow::isOverdue('overdue', '2026-09-30', '2026-09-20'));
        // Picked-up past the return date.
        $this->assertTrue(BorrowingWorkflow::isOverdue('picked_up', '2026-09-19', '2026-09-20'));
        // Picked-up on/before the return date.
        $this->assertFalse(BorrowingWorkflow::isOverdue('picked_up', '2026-09-20', '2026-09-20'));
        $this->assertFalse(BorrowingWorkflow::isOverdue('picked_up', '2026-09-21', '2026-09-20'));
        // Other stages never read overdue.
        $this->assertFalse(BorrowingWorkflow::isOverdue('ready_for_pickup', '2026-09-01', '2026-09-20'));
        $this->assertFalse(BorrowingWorkflow::isOverdue('picked_up', null, '2026-09-20'));
    }

    public function testAssignQuantityClamp(): void
    {
        $this->assertSame(2, BorrowingWorkflow::clampAssignQuantity(2, 5, 3));
        $this->assertSame(3, BorrowingWorkflow::clampAssignQuantity(9, 5, 3));
        $this->assertSame(1, BorrowingWorkflow::clampAssignQuantity(0, 5, 3));
        $this->assertSame(1, BorrowingWorkflow::clampAssignQuantity(4, 0, 3));
    }

    public function testInventoryAssignmentGatedOnDirectorApproval(): void
    {
        $this->assertTrue(BorrowingWorkflow::canAssignInventory('approved_director'));
        $this->assertFalse(BorrowingWorkflow::canAssignInventory('pending_director'));
        $this->assertFalse(BorrowingWorkflow::canAssignInventory('inventory_assigned'));
    }

    public function testReturnPatchAutoArchivesWithNoRating(): void
    {
        $patch = BorrowingWorkflow::returnTicketPatch([]);

        $this->assertSame('closed', $patch['status']);
        $this->assertSame('Returned & Completed', $patch['status_label']);
        $this->assertSame(1, $patch['is_archived']);
        $this->assertSame(7, $patch['current_step']);
        $this->assertArrayHasKey('completed_at', $patch);
    }

    public function testReturnConditions(): void
    {
        foreach (['excellent', 'good', 'fair', 'damaged', 'lost'] as $condition) {
            $this->assertTrue(BorrowingWorkflow::isValidReturnCondition($condition));
        }
        $this->assertFalse(BorrowingWorkflow::isValidReturnCondition('mint'));
        $this->assertFalse(BorrowingWorkflow::isValidReturnCondition(''));
    }
}
