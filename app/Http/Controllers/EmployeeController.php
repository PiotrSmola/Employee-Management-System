<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\EmployeeProfileService;
use App\Support\EmployeeVisibility;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeProfileService $profiles) {}

    public function index()
    {
        // The list itself is rendered by the EmployeeFilter / EmployeeList Livewire components.
        return view('employees.index');
    }

    public function show(Request $request, $emp_no)
    {
        return view('employees.show', $this->profile($request, $emp_no));
    }

    /**
     * Printable version of the profile: available to everyone who may open the
     * profile, with the same (salary) restrictions.
     */
    public function exportShowToPdf(Request $request, $emp_no)
    {
        $profile = $this->profile($request, $emp_no);
        $employee = $profile['employee'];

        $pdf = Pdf::loadView('exports.show-pdf', [...$profile, 'generatedAt' => now()])
            ->setPaper('A4', 'portrait');

        return $pdf->download($this->pdfFilename($employee));
    }

    /**
     * Names are free text and may hold characters that are not allowed in a
     * download filename, such as a slash.
     */
    private function pdfFilename(Employee $employee): string
    {
        $name = preg_replace('/[^A-Za-z0-9-]+/', '_', Str::ascii($employee->first_name.' '.$employee->last_name));

        return 'employee_details_'.$employee->emp_no.'_'.trim($name, '_').'.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(Request $request, $empNo): array
    {
        $employee = Employee::findOrFail($empNo);
        $this->authorize('view', $employee);

        return $this->profiles->build($employee, EmployeeVisibility::for($request->user())->canSeeSalaries());
    }
}
