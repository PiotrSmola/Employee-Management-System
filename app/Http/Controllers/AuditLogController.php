<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Support\AuditLogPresenter;
use App\Support\EmployeeVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * The audit log (`can:view-audit-log`): newest first, filtered by action,
 * subject, user and date range.
 *
 * Stays fast on large logs: every filter maps onto an index (`created_at`,
 * `(action, created_at)`, `(subject_type, subject_id, created_at)`, `user_id`)
 * and the page uses simple pagination (no COUNT over the whole log).
 */
class AuditLogController extends Controller
{
    public const PER_PAGE = 25;

    /** Filter value of `user` for entries without a user (system, failed sign-ins). */
    public const NO_USER = 'none';

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $subjectTypes = ActivityLog::query()->distinct()->orderBy('subject_type')->pluck('subject_type')->all();

        $entries = $this->query($filters, $subjectTypes)
            ->with('user:id,name,email')
            ->latestFirst()
            ->simplePaginate(self::PER_PAGE)
            ->withQueryString();

        $employeeIds = $entries->getCollection()
            ->where('subject_type', ActivityLog::SUBJECT_EMPLOYEE)
            ->pluck('subject_id')
            ->filter(fn ($id) => ctype_digit((string) $id))
            ->unique()
            ->all();

        $departmentNames = Department::query()->pluck('dept_name', 'dept_no')->all();

        return view('audit-log.index', [
            'entries' => $entries,
            'filters' => $filters,
            'actions' => ActivityLog::query()->distinct()->orderBy('action')->pluck('action')->all(),
            'subjectTypes' => $subjectTypes,
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            'employeeNames' => $employeeIds === [] ? collect() : Employee::query()
                ->whereIn('emp_no', $employeeIds)
                ->get(['emp_no', 'first_name', 'last_name'])
                ->mapWithKeys(fn (Employee $e) => [(string) $e->emp_no => $e->first_name.' '.$e->last_name]),
            'departmentNames' => $departmentNames,
            'presenter' => new AuditLogPresenter($departmentNames, EmployeeVisibility::for($request->user())->canSeeSalaries()),
        ]);
    }

    /**
     * The valid filters of the request (invalid values are ignored).
     *
     * @return array{action?: string, subject_type?: string, subject_id?: string, user?: string, from?: string, to?: string}
     */
    private function filters(Request $request): array
    {
        $input = array_filter(
            array_map(fn ($value) => is_string($value) ? trim($value) : $value, $request->only(['action', 'subject_type', 'subject_id', 'user', 'from', 'to'])),
            fn ($value) => $value !== null && $value !== '',
        );

        $valid = Validator::make($input, [
            'action' => ['string', 'max:64', 'regex:/^[a-z0-9_.*]+$/'],
            'subject_type' => ['string', 'max:32'],
            'subject_id' => ['string', 'max:64'],
            'user' => ['string', 'regex:/^(\d+|'.self::NO_USER.')$/'],
            'from' => ['date_format:Y-m-d'],
            'to' => ['date_format:Y-m-d'],
        ])->valid();

        return array_intersect_key($valid, array_flip(['action', 'subject_type', 'subject_id', 'user', 'from', 'to']));
    }

    /**
     * @param  array<string, string>  $filters
     * @param  string[]  $subjectTypes
     */
    private function query(array $filters, array $subjectTypes): Builder
    {
        return ActivityLog::query()
            ->when($filters['action'] ?? null, function (Builder $query, string $action) {
                // "employee.*" = every action of a subject (range on the (action, created_at) index).
                str_ends_with($action, '.*')
                    ? $query->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], substr($action, 0, -1)).'%')
                    : $query->where('action', $action);
            })
            ->when($filters['subject_type'] ?? null, fn (Builder $query, string $type) => $query->where('subject_type', $type))
            ->when($filters['subject_id'] ?? null, function (Builder $query, string $id) use ($filters, $subjectTypes) {
                // Without a type, list the types so the (subject_type, subject_id, created_at) index is used.
                if (! isset($filters['subject_type'])) {
                    $query->whereIn('subject_type', $subjectTypes === [] ? [''] : $subjectTypes);
                }
                $query->where('subject_id', $id);
            })
            ->when($filters['user'] ?? null, fn (Builder $query, string $user) => $user === self::NO_USER
                ? $query->whereNull('user_id')
                : $query->where('user_id', (int) $user))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where(
                'created_at', '<', CarbonImmutable::parse($to)->addDay()->toDateString().' 00:00:00',
            ));
    }
}
