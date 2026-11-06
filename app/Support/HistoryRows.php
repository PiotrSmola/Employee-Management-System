<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * The rule for reading the history tables (dept_emp, dept_manager, titles,
 * salaries): rows are never deleted. Undoing an HR operation marks the rows it
 * added with void_log_id = the id of the undo's audit entry; such rows stay in
 * the table, but do not exist for the application. void_log_id = 0 is an active row.
 *
 * Every query over these tables goes through this class (the models have a
 * global scope, ExcludesVoidedRows; tests/Feature/HrOperations/VoidedRowsGuardTest
 * fails for code that forgets it):
 *
 *   HistoryRows::active(DB::table('salaries'))->where('emp_no', $empNo)->get();
 *   HistoryRows::active($query->from('titles as t'), 't');
 *   ->leftJoin('titles as t', fn ($join) => HistoryRows::active($join->on('t.emp_no', '=', 'e.emp_no'), 't'))
 *   DB::raw('(SELECT ... FROM salaries s WHERE s.emp_no = e.emp_no AND '.HistoryRows::sql('s').')')
 *   ->wherePivot(HistoryRows::COLUMN, HistoryRows::ACTIVE)
 */
final class HistoryRows
{
    public const COLUMN = 'void_log_id';

    /** void_log_id of a row that was not undone. */
    public const ACTIVE = 0;

    public const TABLES = ['dept_emp', 'dept_manager', 'titles', 'salaries'];

    /**
     * Limit $query (Query Builder, Eloquent builder or join clause) to active rows.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @param  string|null  $alias  table or alias, when the query has several tables
     * @return TBuilder
     */
    public static function active(Builder $query, ?string $alias = null): Builder
    {
        return $query->where(self::column($alias), self::ACTIVE);
    }

    /**
     * The same condition for raw SQL. $alias is a table name or alias of the
     * calling code, never user input.
     */
    public static function sql(string $alias): string
    {
        return self::column($alias).' = '.self::ACTIVE;
    }

    private static function column(?string $alias): string
    {
        return $alias === null ? self::COLUMN : $alias.'.'.self::COLUMN;
    }
}
