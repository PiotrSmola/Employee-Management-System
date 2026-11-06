<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Hands out the next free employee number (employees.emp_no is a natural key,
 * not AUTO_INCREMENT).
 *
 * Call it inside the transaction that inserts the employee: the locking read of
 * the highest number (SELECT ... ORDER BY emp_no DESC LIMIT 1 FOR UPDATE) takes a
 * next-key lock on the last index record and the gap above it, so a concurrent
 * hire waits until this transaction commits and then sees the new maximum.
 * Writers that insert explicit numbers without this lock (e.g. imports) can
 * still collide; EmployeeOperationsService::hire() retries on a duplicate key.
 */
class EmployeeNumberAllocator
{
    /** First number of an empty table (the sample dataset starts at 10001). */
    public const FIRST = 10001;

    public function next(): int
    {
        $max = DB::table('employees')->orderByDesc('emp_no')->lockForUpdate()->value('emp_no');

        return $max === null ? self::FIRST : (int) $max + 1;
    }
}
