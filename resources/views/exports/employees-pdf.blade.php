<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Employee Export Report</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            margin: 20px;
            color: #333;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #007bff;
            padding-bottom: 15px;
        }

        .header h1 {
            color: #007bff;
            font-size: 20px;
            margin: 0;
        }

        .header .meta {
            margin-top: 10px;
            color: #666;
            font-size: 10px;
        }

        .stats {
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 8px;
        }

        th {
            background-color: #007bff;
            color: white;
            padding: 8px 4px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #dee2e6;
        }

        td {
            padding: 6px 4px;
            border: 1px solid #dee2e6;
            vertical-align: top;
        }

        tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        tbody tr:hover {
            background-color: #e3f2fd;
        }

        .emp-no {
            font-weight: bold;
            color: #007bff;
        }

        .department {
            background-color: #17a2b8;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 7px;
            display: inline-block;
        }

        .salary {
            background-color: #28a745;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: bold;
        }

        .former-employee {
            background-color: #6c757d;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 7px;
        }

        .last-info {
            font-style: italic;
            color: #555;
        }

        .gender-male {
            color: #007bff;
            font-weight: bold;
        }

        .gender-female {
            color: #e83e8c;
            font-weight: bold;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            color: #666;
            font-size: 8px;
            border-top: 1px solid #dee2e6;
            padding-top: 10px;
        }

        .page-break {
            page-break-before: always;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>Employee Export Report</h1>
        <div class="meta"> <strong>Generated on:</strong> {{ $exportDate }}<br> <strong>Total Records:</strong>
            {{ number_format($totalCount) }} employees @if (isset($isLimited) && $isLimited)
                <br><span style="color: #dc3545;"><strong>Note:</strong> Limited to the first {{ number_format($limit) }} of
                    {{ number_format($selectedCount) }} selected records for PDF export</span>
            @endif
        </div>
    </div>

    <div class="stats"> <strong>Export Summary:</strong> This PDF contains detailed information about
        {{ number_format($totalCount) }} employees including their
        @if ($withSalaries ?? true)
            current salary, job title, department, and total earnings throughout their employment.
        @else
            job title, department and employment status.
        @endif
    </div>


    @php
        // Chosen columns (EmployeeExportService::columns()); older callers pass none.
        $columns ??= ['emp_no', 'full_name', 'department', 'title', ...(($withSalaries ?? true) ? ['salary', 'earnings'] : []), 'hire_date', 'gender', 'status'];
        $labels ??= [
            'emp_no' => 'Emp #', 'first_name' => 'First Name', 'last_name' => 'Last Name', 'full_name' => 'Full Name',
            'department' => 'Department', 'title' => 'Job Title', 'salary' => 'Current Salary', 'earnings' => 'Total Earnings',
            'hire_date' => 'Hire Date', 'gender' => 'Gender', 'status' => 'Status',
        ];
        $rowsPerPage = 40;
    @endphp

    @foreach ($employees->chunk($rowsPerPage) as $page)
        @if (! $loop->first)
            <div class="page-break"></div>
        @endif
        <table>
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th>{{ $column === 'emp_no' ? 'Emp #' : ($labels[$column] ?? $column) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($page as $employee)
                    <tr>
                        @foreach ($columns as $column)
                            @switch($column)
                                @case('emp_no')
                                    <td class="emp-no">{{ $employee->emp_no }}</td>
                                @break

                                @case('first_name')
                                    <td>{{ $employee->first_name }}</td>
                                @break

                                @case('last_name')
                                    <td>{{ $employee->last_name }}</td>
                                @break

                                @case('full_name')
                                    <td><strong>{{ $employee->first_name }} {{ $employee->last_name }}</strong></td>
                                @break

                                @case('department')
                                    <td>
                                        @if ($employee->current_dept_name)
                                            <span class="department">{{ $employee->current_dept_name }}</span>
                                        @elseif ($employee->last_dept_name)
                                            <span class="former-employee">Former - {{ $employee->last_dept_name }}</span>
                                        @else
                                            <span class="former-employee">N/A</span>
                                        @endif
                                    </td>
                                @break

                                @case('title')
                                    <td>
                                        @if ($employee->current_title)
                                            {{ $employee->current_title }}
                                        @elseif ($employee->last_job_title)
                                            <span class="last-info">{{ $employee->last_job_title }} (Last)</span>
                                        @else
                                            <em>N/A</em>
                                        @endif
                                    </td>
                                @break

                                @case('salary')
                                    <td>
                                        @if ($employee->current_salary)
                                            <span class="salary">${{ number_format($employee->current_salary) }}</span>
                                        @else
                                            <em>N/A</em>
                                        @endif
                                    </td>
                                @break

                                @case('earnings')
                                    <td><strong>${{ number_format((float) $employee->total_earnings) }}</strong></td>
                                @break

                                @case('hire_date')
                                    <td>{{ \Carbon\Carbon::parse($employee->hire_date)->format('M d, Y') }}</td>
                                @break

                                @case('gender')
                                    <td>
                                        <span class="{{ $employee->gender === 'M' ? 'gender-male' : 'gender-female' }}">
                                            {{ $employee->gender === 'M' ? 'M' : 'F' }}</span>
                                    </td>
                                @break

                                @case('status')
                                    <td>{{ $employee->current_dept_name ? 'Active' : 'Former' }}</td>
                                @break
                            @endswitch
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <div class="footer">
        <p>
            <strong>Employee Management System</strong> | Exported employees data | Page contains
            {{ number_format($totalCount) }} records
        </p>
    </div>
</body>

</html>
