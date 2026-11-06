<?php

namespace App\Http\Controllers;

use App\Http\Requests\Employees\ChangeSalaryRequest;
use App\Http\Requests\Employees\ChangeTitleRequest;
use App\Http\Requests\Employees\CorrectSalaryRequest;
use App\Http\Requests\Employees\CorrectTitleRequest;
use App\Http\Requests\Employees\HireEmployeeRequest;
use App\Http\Requests\Employees\RehireEmployeeRequest;
use App\Http\Requests\Employees\TerminateEmployeeRequest;
use App\Http\Requests\Employees\TransferEmployeeRequest;
use App\Http\Requests\Employees\UpdatePersonalDataRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Services\EmployeeOperationsService;
use App\Support\EmploymentRules;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * HR operations (routes/web/hr-operations.php, every route behind
 * `can:manage-employees`). The forms of the profile are modals; each one posts
 * here and comes back to the profile with a flash message, or with the errors
 * in the form's own error bag (the modal is opened again).
 */
class EmployeeOperationsController extends Controller
{
    public function __construct(private readonly EmployeeOperationsService $operations)
    {
    }

    public function create(): View
    {
        $this->authorize('create', Employee::class);

        return view('employees.create', [
            'departments' => Department::query()->orderBy('dept_name')->get(['dept_no', 'dept_name']),
            'titleOptions' => $this->operations->titleOptions(),
            'today' => EmploymentRules::today(),
        ]);
    }

    public function store(HireEmployeeRequest $request): RedirectResponse
    {
        $this->authorize('create', Employee::class);

        $employee = $this->run('default', route('employees.create'), fn () => $this->operations->hire($request->validated()));

        return redirect()->route('employees.show', $employee->emp_no)->with('status', __(
            ':name has been hired (employee no. :number).',
            ['name' => $this->name($employee), 'number' => $employee->emp_no],
        ));
    }

    public function update(UpdatePersonalDataRequest $request, int $emp_no): RedirectResponse
    {
        $employee = $this->employee($emp_no);

        $changes = $this->run('personal', $this->profileUrl($employee), fn () => $this->operations->updatePersonalData($employee, $request->validated()));

        return $this->backToProfile($employee, $changes === []
            ? __('Nothing to save - the personal data is unchanged.')
            : __('The personal data of :name has been updated.', ['name' => $this->name($employee->refresh())]));
    }

    public function transfer(TransferEmployeeRequest $request, int $emp_no): RedirectResponse
    {
        $employee = $this->employee($emp_no);
        $date = $request->validated('effective_date');

        $result = $this->run('transfer', $this->profileUrl($employee), fn () => $this->operations->transfer($employee, $request->validated('dept_no'), $date));

        $names = Department::query()->whereIn('dept_no', [...$result['from'], $result['to']])->pluck('dept_name', 'dept_no');
        $message = __(':name moves from :from to :to on :date.', [
            'name' => $this->name($employee),
            'from' => collect($result['from'])->map(fn ($no) => $names[$no] ?? $no)->implode(', '),
            'to' => $names[$result['to']] ?? $result['to'],
            'date' => EmploymentRules::display($date),
        ]);
        if ($result['ended_management'] !== []) {
            $message .= ' '.__('The management of :department ended on the same date.', [
                'department' => collect($result['ended_management'])->map(fn ($no) => $names[$no] ?? $no)->implode(', '),
            ]);
        }

        return $this->backToProfile($employee, $message);
    }

    public function changeTitle(ChangeTitleRequest $request, int $emp_no): RedirectResponse
    {
        $employee = $this->employee($emp_no);
        $data = $request->validated();

        $this->run('title', $this->profileUrl($employee), fn () => $this->operations->changeTitle($employee, $data['title'], $data['effective_date'], $data['kind']));

        return $this->backToProfile($employee, __('The job title of :name is :title from :date.', [
            'name' => $this->name($employee),
            'title' => $data['title'],
            'date' => EmploymentRules::display($data['effective_date']),
        ]));
    }

    public function changeSalary(ChangeSalaryRequest $request, int $emp_no): RedirectResponse
    {
        $employee = $this->employee($emp_no);
        $data = $request->validated();

        $result = $this->run('salary', $this->profileUrl($employee), fn () => $this->operations->changeSalary(
            $employee,
            $data['mode'] === 'amount' ? (int) $data['salary'] : null,
            $data['mode'] === 'percent' ? (float) $data['percent'] : null,
            $data['effective_date'],
        ));

        return $this->backToProfile($employee, __('The salary of :name changes from :before to :after on :date.', [
            'name' => $this->name($employee),
            'before' => number_format($result['before']),
            'after' => number_format($result['after']),
            'date' => EmploymentRules::display($data['effective_date']),
        ]));
    }

    public function terminate(TerminateEmployeeRequest $request, int $emp_no): RedirectResponse
    {
        $employee = $this->employee($emp_no);
        $data = $request->validated();

        $this->run('terminate', $this->profileUrl($employee), fn () => $this->operations->terminate($employee, $data['end_date'], $data['reason'] ?? null));

        return $this->backToProfile($employee, __('The employment of :name ended on :date.', [
            'name' => $this->name($employee),
            'date' => EmploymentRules::display($data['end_date']),
        ]));
    }

    public function rehire(RehireEmployeeRequest $request, int $emp_no): RedirectResponse
    {
        $employee = $this->employee($emp_no);
        $data = $request->validated();

        $this->run('rehire', $this->profileUrl($employee), fn () => $this->operations->rehire(
            $employee, $data['dept_no'], $data['title'], (int) $data['salary'], $data['effective_date'],
        ));

        return $this->backToProfile($employee, __(':name has been rehired from :date.', [
            'name' => $this->name($employee),
            'date' => EmploymentRules::display($data['effective_date']),
        ]));
    }

    public function correctSalary(CorrectSalaryRequest $request, int $emp_no): RedirectResponse
    {
        $employee = $this->employee($emp_no);

        $this->run('correctSalary', $this->profileUrl($employee), fn () => $this->operations->correctLatestSalary($employee, (int) $request->validated('salary')));

        return $this->backToProfile($employee, __('The latest salary of :name has been corrected.', ['name' => $this->name($employee)]));
    }

    public function correctTitle(CorrectTitleRequest $request, int $emp_no): RedirectResponse
    {
        $employee = $this->employee($emp_no);

        $this->run('correctTitle', $this->profileUrl($employee), fn () => $this->operations->correctLatestTitle($employee, $request->validated('title')));

        return $this->backToProfile($employee, __('The latest job title of :name has been corrected.', ['name' => $this->name($employee)]));
    }

    private function employee(int $empNo): Employee
    {
        $employee = Employee::findOrFail($empNo);
        $this->authorize('update', $employee);

        return $employee;
    }

    /**
     * Run an operation; its validation errors go to the form's error bag and back to $url.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function run(string $errorBag, string $url, Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $e) {
            throw $e->errorBag($errorBag)->redirectTo($url);
        }
    }

    private function backToProfile(Employee $employee, string $message): RedirectResponse
    {
        return redirect()->to($this->profileUrl($employee))->with('status', $message);
    }

    private function profileUrl(Employee $employee): string
    {
        return route('employees.show', $employee->emp_no);
    }

    private function name(Employee $employee): string
    {
        return trim($employee->first_name.' '.$employee->last_name);
    }
}
