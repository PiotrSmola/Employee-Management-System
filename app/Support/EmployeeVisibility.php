<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\User;
use App\Queries\EmployeeFilters;
use App\Queries\EmployeeSearchQuery;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What part of the employee data a user may see - the one place that applies
 * the row and column restrictions of docs/AUTHORIZATION.md:
 *
 *  - rows: a manager only sees the employees of their department (users.dept_no):
 *    the current department, or for former employees the last one - the same
 *    definition as the directory's department filter
 *    ({@see EmployeeSearchQuery::departmentMembership()}). A manager without a
 *    department sees nobody. Every other role sees everyone.
 *  - columns: salary data only with the `view-salaries` ability.
 *
 * Usage:
 *
 *   $visibility = EmployeeVisibility::for($request->user());
 *   $query = $visibility->query($request->all());           // directory search, scope + salary rules applied
 *   $visibility->constrain($builder, 'e.emp_no');            // any other query builder over employees
 *   $visibility->canView($employee);                         // single employee (used by EmployeePolicy)
 *   $visibility->canSeeSalaries();
 */
final class EmployeeVisibility
{
    /** Department filter value that matches no employee (manager without a department). */
    public const NO_DEPARTMENT = '-';

    private function __construct(
        private readonly bool $scoped,
        private readonly ?string $department,
        private readonly bool $salaries,
    ) {
    }

    public static function for(User $user): self
    {
        $scoped = ! $user->is_active || $user->role->isDepartmentScoped();

        return new self(
            scoped: $scoped,
            department: $scoped && $user->is_active && $user->dept_no ? $user->dept_no : null,
            salaries: Ability::check($user, Ability::VIEW_SALARIES),
        );
    }

    /**
     * The authenticated user's visibility.
     *
     * @throws AuthenticationException
     */
    public static function current(): self
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return self::for($user);
    }

    /**
     * Everything, including salaries - only for trusted system code (console
     * commands, scheduled jobs), never for a request.
     */
    public static function unrestricted(): self
    {
        return new self(scoped: false, department: null, salaries: true);
    }

    public function canSeeSalaries(): bool
    {
        return $this->salaries;
    }

    /**
     * Is the user limited to a single department (manager)?
     */
    public function isDepartmentScoped(): bool
    {
        return $this->scoped;
    }

    /**
     * The department the user is limited to; null when not scoped, or when a
     * scoped user has no department (then they see nobody).
     */
    public function department(): ?string
    {
        return $this->department;
    }

    /**
     * Directory filters with the restrictions applied: the department filter
     * forced to the manager's department, salary filters and the salary sort
     * dropped without view-salaries.
     *
     * @param  EmployeeFilters|array<string, mixed>  $filters
     */
    public function filters(EmployeeFilters|array $filters): EmployeeFilters
    {
        $filters = $filters instanceof EmployeeFilters ? $filters : EmployeeFilters::fromArray($filters);
        $changes = [];

        if ($this->scoped) {
            $changes['department'] = $this->department ?? self::NO_DEPARTMENT;
        }

        if (! $this->salaries) {
            $changes['salaryMin'] = null;
            $changes['salaryMax'] = null;

            if ($filters->sortBy === 'salary') {
                $changes['sortBy'] = 'emp_no';
            }
        }

        return $changes === [] ? $filters : $filters->with($changes);
    }

    /**
     * Directory search restricted to what the user may see.
     *
     * @param  EmployeeFilters|array<string, mixed>  $filters
     */
    public function query(EmployeeFilters|array $filters = []): EmployeeSearchQuery
    {
        return EmployeeSearchQuery::make($this->filters($filters));
    }

    /**
     * Restrict any query over employees to the visible ones.
     * $empNoColumn is the (qualified) employee number column of $query.
     *
     * @template T of Builder|EloquentBuilder
     *
     * @param  T  $query
     * @return T
     */
    public function constrain(Builder|EloquentBuilder $query, string $empNoColumn = 'emp_no'): Builder|EloquentBuilder
    {
        if (! $this->scoped) {
            return $query;
        }

        if ($this->department === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($empNoColumn, fn (Builder $q) => EmployeeSearchQuery::departmentMembership(
            $q->select('de_f.emp_no')->from('dept_emp as de_f'),
            $this->department
        ));
    }

    public function canView(Employee|int|string $employee): bool
    {
        if (! $this->scoped) {
            return true;
        }

        if ($this->department === null) {
            return false;
        }

        $empNo = $employee instanceof Employee ? $employee->emp_no : (int) $employee;

        return EmployeeSearchQuery::departmentMembership(
            DB::table('dept_emp as de_f')->where('de_f.emp_no', $empNo),
            $this->department
        )->exists();
    }
}
