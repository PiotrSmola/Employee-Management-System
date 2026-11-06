<?php

namespace App\Services;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Hands out the next free employee number (employees.emp_no is a natural key,
 * not AUTO_INCREMENT). Everything that creates employees - a hire, an import -
 * goes through this class:
 *
 *   $numbers->transaction(function () use ($numbers) {
 *       $empNo = $numbers->next();
 *       // insert the employee(s) from $empNo on
 *   });
 *
 * The locking read of the highest number (SELECT ... ORDER BY emp_no DESC LIMIT 1
 * FOR UPDATE) takes a next-key lock on the last index record and the gap above
 * it, so a concurrent allocation waits until this transaction commits and then
 * sees the new maximum. Concurrent allocations can still deadlock on that gap
 * (InnoDB gives one of them up), and a writer that inserts explicit numbers
 * without the lock can take a number: {@see transaction()} repeats the whole
 * transaction, with the number allocated anew.
 */
class EmployeeNumberAllocator
{
    /** First number of an empty table (the sample dataset starts at 10001). */
    public const FIRST = 10001;

    /** Attempts of a transaction that allocates numbers. */
    public const ATTEMPTS = 5;

    /** MySQL errors after which a transaction can simply be repeated: deadlock, lock wait timeout. */
    private const RETRYABLE_ERRORS = [1205, 1213];

    /**
     * The next free number; call inside {@see transaction()}. A caller creating
     * several employees uses the numbers from here on.
     */
    public function next(): int
    {
        $last = DB::table('employees')->orderByDesc('emp_no')->lockForUpdate()->value('emp_no');

        return max((int) $last, self::FIRST - 1) + 1;
    }

    /**
     * Run $operation in a transaction, again (with a short random pause) when the
     * number was taken meanwhile or, unless it is part of an outer transaction, on
     * a deadlock or lock wait timeout: those roll back the whole transaction,
     * which only the outermost caller can repeat.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function transaction(Closure $operation): mixed
    {
        $outermost = DB::transactionLevel() === 0;

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($operation);
            } catch (UniqueConstraintViolationException $e) {
                $retry = true;
            } catch (QueryException $e) {
                $retry = $outermost && in_array((int) ($e->errorInfo[1] ?? 0), self::RETRYABLE_ERRORS, true);
            }

            if (! $retry || $attempt >= self::ATTEMPTS) {
                throw $e;
            }

            usleep(random_int(5_000, 25_000) * $attempt);
        }
    }
}
