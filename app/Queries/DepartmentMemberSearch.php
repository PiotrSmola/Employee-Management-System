<?php

namespace App\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Current employees of a department matching a search term - the choices of
 * the "assign manager" select. A numeric term matches the employee number
 * (prefix), otherwise every word has to be the start of the first or last name.
 *
 * Reads the department's current dept_emp rows through the (dept_no, to_date)
 * index, so it stays fast for the largest department (~61k current employees).
 */
class DepartmentMemberSearch
{
    public const CURRENT = '9999-01-01';

    public const LIMIT = 20;

    /**
     * @return Collection<int, object{emp_no: int, first_name: string, last_name: string, since: string, title: ?string}>
     */
    public function search(string $deptNo, string $term, int $limit = self::LIMIT): Collection
    {
        $term = trim($term);

        $rows = DB::table('dept_emp as de')
            ->join('employees as e', 'e.emp_no', '=', 'de.emp_no')
            ->where('de.dept_no', $deptNo)
            ->where('de.to_date', self::CURRENT)
            ->when($term !== '', fn (Builder $q) => $this->match($q, $term))
            ->orderBy('e.last_name')
            ->orderBy('e.first_name')
            ->orderBy('e.emp_no')
            ->limit($limit)
            ->get(['e.emp_no', 'e.first_name', 'e.last_name', 'de.from_date as since']);

        $titles = $rows->isEmpty() ? collect() : DB::table('titles')
            ->whereIn('emp_no', $rows->pluck('emp_no'))
            ->where('to_date', self::CURRENT)
            ->pluck('title', 'emp_no');

        return $rows->map(fn ($row) => (object) [
            'emp_no' => (int) $row->emp_no,
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'since' => $row->since,
            'title' => $titles[$row->emp_no] ?? null,
        ]);
    }

    private function match(Builder $query, string $term): Builder
    {
        if (ctype_digit($term)) {
            return $query->whereRaw('cast(e.emp_no as char) like ?', [$this->prefix($term)]);
        }

        foreach (preg_split('/\s+/u', $term) ?: [] as $word) {
            $query->where(fn (Builder $w) => $w
                ->where('e.first_name', 'like', $this->prefix($word))
                ->orWhere('e.last_name', 'like', $this->prefix($word)));
        }

        return $query;
    }

    private function prefix(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}
