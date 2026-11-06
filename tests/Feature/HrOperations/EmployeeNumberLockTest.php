<?php

namespace Tests\Feature\HrOperations;

use App\Services\EmployeeNumberAllocator;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two real connections: while one transaction holds the number lock, a second
 * connection's allocation has to wait (here: until its lock wait timeout).
 *
 * The test's own transaction (RefreshDatabase) plays "hire A"; a second
 * connection to the same database plays "hire B". The row the lock is taken on
 * is committed by the second connection, so it is not hidden by the test
 * transaction, and removed again after the test.
 */
class EmployeeNumberLockTest extends TestCase
{
    use RefreshDatabase;

    private const EMP_NO = 777001;

    private function second(): Connection
    {
        config(['database.connections.mysql_second' => config('database.connections.'.config('database.default'))]);

        return DB::connection('mysql_second');
    }

    private function lockingRead(Connection $connection): ?int
    {
        return $connection->table('employees')->orderByDesc('emp_no')->lockForUpdate()->value('emp_no');
    }

    public function test_a_second_allocation_waits_for_the_first_transaction(): void
    {
        $second = $this->second();
        $second->table('employees')->insert([
            'emp_no' => self::EMP_NO, 'birth_date' => '1970-01-01', 'first_name' => 'Lock', 'last_name' => 'Row',
            'gender' => 'M', 'hire_date' => '2000-01-01',
        ]);
        $this->beforeApplicationDestroyed(function () {
            // Runs after RefreshDatabase rolled back the test transaction (which released the lock).
            DB::connection('mysql_second')->table('employees')->where('emp_no', self::EMP_NO)->delete();
            DB::purge('mysql_second');
        });
        $second->statement('SET SESSION innodb_lock_wait_timeout = 1');

        // Nobody holds the lock yet: the second connection gets it at once.
        $second->transaction(fn () => $this->assertSame(self::EMP_NO, $this->lockingRead($second)));

        // "Hire A" allocates inside its (still open) transaction ...
        $this->assertGreaterThan(0, DB::transactionLevel());
        $this->assertSame(self::EMP_NO + 1, app(EmployeeNumberAllocator::class)->next());

        // ... so "hire B" cannot read the highest number until A commits.
        $second->beginTransaction();
        try {
            $this->lockingRead($second);
            $this->fail('The second allocation should have waited for the lock.');
        } catch (QueryException $e) {
            $this->assertSame(1205, (int) $e->errorInfo[1], 'Expected a lock wait timeout.');
        } finally {
            $second->rollBack();
        }
    }
}
