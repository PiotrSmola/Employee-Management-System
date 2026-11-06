<?php

namespace App\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Current employees of a department matching a search term - the choices of
 * the "assign manager" select. A numeric term matches the employee number
 * (prefix), otherwise every word has to be the start of the first or last name
 * and the term needs at least MIN_NAME_LENGTH characters. Earlier and current
 * managers of the department are marked (`manager`: current, former or null).
 *
 * Without a term only the (dept_no, to_date) index is read. There is no index on
 * names, so a name search looks at each employee once; only the matches are sorted.
 */
class DepartmentMemberSearch
{
    public const CURRENT = '9999-01-01';

    public const LIMIT = 20;

    public const MIN_NAME_LENGTH = 2;

    private const COLUMNS = ['e.emp_no', 'e.first_name', 'e.last_name', 'de.from_date as since'];

    /**
     * @return Collection<int, object{emp_no: int, first_name: string, last_name: string, since: string, title: ?string, manager: ?string}>
     */
    public function search(string $deptNo, string $term, int $limit = self::LIMIT): Collection
    {
        $term = trim($term);

        if ($term !== '' && ! ctype_digit($term) && mb_strlen($term) < self::MIN_NAME_LENGTH) {
            return collect();
        }

        $rows = $term === '' ? $this->first($deptNo, $limit) : $this->matching($deptNo, $term, $limit);

        $titles = $rows->isEmpty() ? collect() : DB::table('titles')
            ->whereIn('emp_no', $rows->pluck('emp_no'))
            ->where('to_date', self::CURRENT)
            ->pluck('title', 'emp_no');

        $managers = $this->managerRoles($deptNo, $rows->pluck('emp_no'));

        return $rows->map(fn ($row) => (object) [
            'emp_no' => (int) $row->emp_no,
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'since' => $row->since,
            'title' => $titles[$row->emp_no] ?? null,
            'manager' => $managers[$row->emp_no] ?? null,
        ]);
    }

    /**
     * emp_no => "current" or "former", for those who managed the department.
     *
     * @param  Collection<int, int|string>  $empNos
     * @return array<int, string>
     */
    private function managerRoles(string $deptNo, Collection $empNos): array
    {
        $roles = [];

        $periods = DB::table('dept_manager')
            ->where('dept_no', $deptNo)
            ->whereIn('emp_no', $empNos)
            ->get(['emp_no', 'to_date']);

        foreach ($periods as $period) {
            $current = $period->to_date === self::CURRENT;
            $roles[$period->emp_no] = $current || ($roles[$period->emp_no] ?? null) === 'current' ? 'current' : 'former';
        }

        return $roles;
    }

    /**
     * Without a term: the first employees by number, read from the (dept_no,
     * to_date) index alone, which is already in that order.
     */
    private function first(string $deptNo, int $limit): Collection
    {
        $ids = DB::table('dept_emp')
            ->where('dept_no', $deptNo)
            ->where('to_date', self::CURRENT)
            ->orderBy('emp_no')
            ->limit($limit)
            ->pluck('emp_no');

        return $this->members($deptNo)->whereIn('e.emp_no', $ids)->orderBy('e.emp_no')->get(self::COLUMNS);
    }

    private function matching(string $deptNo, string $term, int $limit): Collection
    {
        return $this->match($this->members($deptNo), $term)
            ->orderBy('e.last_name')
            ->orderBy('e.first_name')
            ->orderBy('e.emp_no')
            ->limit($limit)
            ->get(self::COLUMNS);
    }

    private function members(string $deptNo): Builder
    {
        return DB::table('dept_emp as de')
            ->join('employees as e', 'e.emp_no', '=', 'de.emp_no')
            ->where('de.dept_no', $deptNo)
            ->where('de.to_date', self::CURRENT);
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
