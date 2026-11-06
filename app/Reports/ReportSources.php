<?php

namespace App\Reports;

use App\Support\HistoryRows;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The only way the reports reach the history tables (dept_emp, titles, salaries),
 * so a condition that applies to every history row goes into {@see rowFilter()}.
 *
 *   ReportSources::deptEmp('de')->where(...);                     // FROM
 *   ReportSources::joinTitles($query, 't', fn ($j) => $j->on(...), 'left');
 *   ReportSources::open($query, 'de', $date);                     // rows that count on a day
 *   'EXISTS (SELECT 1 FROM '.ReportSources::DEPT_EMP.' o WHERE '.ReportSources::rowFilter('o').' AND ...)'
 */
final class ReportSources
{
    public const DEPT_EMP = 'dept_emp';

    public const TITLES = 'titles';

    public const SALARIES = 'salaries';

    /** SQL condition every counted row of a history table must meet ($alias: constant from the report code). */
    public static function rowFilter(string $alias): string
    {
        return HistoryRows::sql($alias);
    }

    public static function deptEmp(string $alias = 'de'): Builder
    {
        return self::table(self::DEPT_EMP, $alias);
    }

    public static function titles(string $alias = 't'): Builder
    {
        return self::table(self::TITLES, $alias);
    }

    public static function salaries(string $alias = 's'): Builder
    {
        return self::table(self::SALARIES, $alias);
    }

    /**
     * @param  Closure(JoinClause): mixed  $on
     */
    public static function joinDeptEmp(Builder $query, string $alias, Closure $on, string $type = 'inner'): Builder
    {
        return self::join($query, self::DEPT_EMP, $alias, $on, $type);
    }

    /**
     * @param  Closure(JoinClause): mixed  $on
     */
    public static function joinTitles(Builder $query, string $alias, Closure $on, string $type = 'inner'): Builder
    {
        return self::join($query, self::TITLES, $alias, $on, $type);
    }

    /**
     * @param  Closure(JoinClause): mixed  $on
     */
    public static function joinSalaries(Builder $query, string $alias, Closure $on, string $type = 'inner'): Builder
    {
        return self::join($query, self::SALARIES, $alias, $on, $type);
    }

    /**
     * Rows of $alias open on $date: from_date <= day < to_date.
     *
     * @template T of Builder
     *
     * @param  T  $query  a query or join clause
     * @return T
     */
    public static function open(Builder $query, string $alias, string $date): Builder
    {
        return $query->where($alias.'.from_date', '<=', $date)->where($alias.'.to_date', '>', $date);
    }

    private static function table(string $table, string $alias): Builder
    {
        $source = $table.' as '.$alias;

        return HistoryRows::active(DB::table($source), $alias);
    }

    /**
     * @param  Closure(JoinClause): mixed  $on
     */
    private static function join(Builder $query, string $table, string $alias, Closure $on, string $type): Builder
    {
        return $query->join($table.' as '.$alias, function (JoinClause $join) use ($on, $alias) {
            $on($join);
            HistoryRows::active($join, $alias);
        }, type: $type);
    }
}
