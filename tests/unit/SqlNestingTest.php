<?php

use CodeIgniter\Test\CIUnitTestCase;

final class SqlNestingTest extends CIUnitTestCase
{
    public function testStoredProcedureNestingBalance(): void
    {
        $file = HOMEPATH . 'Hostinger Database Updates/gso_db_dump.sql';
        $content = file_get_contents($file);
        
        $startPos = strpos($content, 'CREATE PROCEDURE `sp_gso_upgrade_schema`');
        $endPos   = strpos($content, 'END $$', $startPos);
        $this->assertNotFalse($startPos, 'Procedure start not found');
        $this->assertNotFalse($endPos, 'Procedure end not found');

        $procBody = substr($content, $startPos, $endPos - $startPos);

        // Remove comments
        $procBody = preg_replace('/--[^\n]*/', '', $procBody);

        // Find IF ... THEN and END IF
        preg_match_all('/\bEND\s+IF\b|\bIF\b(?=[^;]+?\bTHEN\b)/is', $procBody, $matches, PREG_OFFSET_CAPTURE);

        $stack = [];
        $unmatched = [];

        foreach ($matches[0] as $match) {
            $token = strtoupper(preg_replace('/\s+/', ' ', trim($match[0])));
            $offset = $match[1];
            $line = substr_count(substr($procBody, 0, $offset), "\n") + 1;

            if ($token === 'IF') {
                $stack[] = $line;
            } elseif ($token === 'END IF') {
                if (empty($stack)) {
                    $unmatched[] = "Unexpected END IF at relative line {$line}";
                } else {
                    array_pop($stack);
                }
            }
        }

        if (!empty($stack)) {
            foreach ($stack as $unclosedLine) {
                $unmatched[] = "Unclosed IF statement starting at relative line {$unclosedLine}";
            }
        }

        $this->assertSame([], $unmatched, 'IF/END IF nesting balance failed: ' . implode('; ', $unmatched));
    }
}
