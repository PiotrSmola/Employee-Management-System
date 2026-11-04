<?php

namespace App\Http\Controllers;

use App\Services\EmployeeProfileService;
use Barryvdh\DomPDF\Facade\Pdf;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeProfileService $profiles)
    {
    }

    public function index()
    {
        // The list itself is rendered by the EmployeeFilter / EmployeeList Livewire components.
        return view('employees.index');
    }

    public function show($emp_no)
    {
        return view('employees.show', $this->profiles->build($emp_no));
    }

    public function exportShowToPdf($emp_no)
    {
        $profile = $this->profiles->build($emp_no);
        $employee = $profile['employee'];

        $pdf = Pdf::loadView('exports.show-pdf', $profile)
            ->setPaper('A4', 'portrait')
            ->setOptions(['isHtml5ParserEnabled' => true, 'isPhpEnabled' => true, 'defaultFont' => 'DejaVu Sans']);

        return $pdf->download('employee_details_'.$employee->emp_no.'_'.$employee->first_name.'_'.$employee->last_name.'.pdf');
    }
}
