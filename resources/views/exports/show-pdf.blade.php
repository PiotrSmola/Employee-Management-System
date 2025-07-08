<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Employee Details - {{ $employee->first_name }} {{ $employee->last_name }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9px;
            margin: 10px;
            padding: 0;
            color: #333;
            background-color: #fff;
            line-height: 1.3;
        }

        .page {
            width: 100%;
            max-width: 780px;
            margin: 0 auto;
        }

        .mb-3 {
            margin-bottom: 12px !important;
        }

        .mb-2 {
            margin-bottom: 8px !important;
        }

        .mb-1 {
            margin-bottom: 4px !important;
        }

        .mb-0 {
            margin-bottom: 0 !important;
        }

        .mt-2 {
            margin-top: 8px !important;
        }

        .me-2 {
            margin-right: 8px !important;
        }

        .me-1 {
            margin-right: 4px !important;
        }

        .ms-1 {
            margin-left: 4px !important;
        }

        .text-center {
            text-align: center !important;
        }

        .text-white {
            color: #fff !important;
        }

        h1 {
            font-size: 16px;
            font-weight: 700;
            margin: 0 0 4px 0;
            line-height: 1.2;
        }

        h4 {
            font-size: 12px;
            font-weight: 600;
            margin: 0 0 4px 0;
            line-height: 1.2;
        }

        h5 {
            font-size: 13px;
            font-weight: 600;
            margin: 0 0 3px 0;
            line-height: 1.2;
        }

        h6 {
            font-size: 9px;
            font-weight: 600;
            margin: 0 0 2px 0;
            line-height: 1.2;
        }

        p {
            margin: 0 0 4px 0;
            font-size: 12px;
            line-height: 1.3;
        }

        small {
            font-size: 7px;
            line-height: 1.2;
        }

        .container {
            width: 100%;
            padding: 0 5px;
        }

        .row {
            width: 100%;
            margin-bottom: 8px;
            page-break-inside: avoid;
            overflow: hidden;
        }

        .col-12 {
            width: 100%;
        }

        .col-6 {
            width: 48%;
            float: left;
            margin-right: 4%;
        }

        .col-4 {
            width: 31.33%;
            float: left;
            margin-right: 3%;
        }

        .col-3 {
            width: 23%;
            float: left;
            margin-right: 2.67%;
        }

        .col-6:last-child,
        .col-4:last-child,
        .col-3:last-child {
            margin-right: 0;
        }

        .card {
            border: none;
            border-radius: 6px;
            margin-bottom: 8px;
            overflow: hidden;
            page-break-inside: avoid;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .card-body {
            padding: 10px;
        }

        .card-header {
            padding: 8px 10px;
            margin-bottom: 0;
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            border-radius: 6px 6px 0 0;
        }

        .card-header h5 {
            margin: 0;
            font-size: 10px;
            font-weight: 600;
            color: #495057;
        }

        .gradient-card-1 {
            background: #667eea;
            color: white;
        }

        .gradient-card-2 {
            background: #f093fb;
            color: white;
        }

        .gradient-card-3 {
            background: #4facfe;
            color: white;
        }

        .gradient-card-4 {
            background: #43e97b;
            color: white;
        }

        .header-card {
            background: #667eea;
            color: white;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 12px;
            overflow: hidden;
        }

        .header-left {
            width: 65%;
            float: left;
            padding-right: 15px;
        }

        .header-right {
            width: 35%;
            float: right;
            text-align: right;
        }

        .stats-container {
            width: 100%;
            margin-bottom: 12px;
            overflow: hidden;
        }

        .stats-card {
            width: 21%;
            float: left;
            margin-right: 2.1%;
            text-align: center;
            border-radius: 6px;
            color: white;
            min-height: 50px;
            padding: 12px 8px;
            position: relative;
        }

        .stats-card:last-child {
            margin-right: 0;
        }

        .stats-card h4 {
            margin: 8px 0 4px 0;
        }

        .stats-card p {
            margin: 0;
        }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            font-size: 7px;
            font-weight: 600;
            line-height: 1;
            color: #fff;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 3px;
            margin-left: 3px;
            margin-top: 2px;
        }

        .badge-current {
            display: inline-block;
            padding: 2px 6px;
            font-size: 8px;
            font-weight: 600;
            line-height: 1;
            color: #fff;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 3px;
            margin-left: 3px;
            margin-top: 2px;
        }

        .bg-white {
            background-color: #fff !important;
            color: #0d6efd !important;
        }

        .bg-secondary {
            background-color: #6c757d !important;
        }

        .bg-success {
            background-color: #198754 !important;
        }

        .bg-department {
            background-color: #6366f1 !important;
        }

        .bg-salary {
            background-color: #059669 !important;
        }

        /* Combined History Table */
        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 8px;
            page-break-inside: auto;
        }

        .history-table th {
            background-color: #007bff;
            color: white;
            padding: 6px 4px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #dee2e6;
            font-size: 7px;
        }

        .history-table td {
            padding: 5px 4px;
            border: 1px solid #dee2e6;
            vertical-align: top;
            font-size: 7px;
        }

        .history-table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .history-table .current-row {
            background-color: #e9ecef !important;
        }

        .history-table .period-col {
            width: 20%;
        }

        .history-table .salary-col {
            width: 25%;
        }

        .history-table .title-col {
            width: 27.5%;
        }

        .history-table .dept-col {
            width: 27.5%;
        }

        .fw-semibold {
            font-weight: 600;
        }

        .fw-medium {
            font-weight: 500;
        }

        .text-muted {
            color: #6c757d;
        }

        .text-success {
            color: #198754;
        }

        .opacity-90 {
            opacity: 0.9;
        }

        .opacity-75 {
            opacity: 0.75;
        }

        .no-break {
            page-break-inside: avoid;
        }

        .allow-break {
            page-break-inside: auto;
        }

        /* Icon replacement (since we can't use Bootstrap icons in PDF) */
        .icon-text::before {
            content: "• ";
            font-weight: bold;
            margin-right: 2px;
        }

        .current-indicator::before {
            content: "✓ ";
            color: #198754;
            font-weight: bold;
        }

        @media print {
            body {
                margin: 5px;
                font-size: 8px;
            }

            .page {
                max-width: none;
            }
        }
    </style>
</head>

<body>
    <div class="page">
        <div class="header-card no-break">
            <div class="header-left">
                <h1>{{ $employee->first_name }} {{ $employee->last_name }}</h1>
                <p class="opacity-90 mb-1">
                    <span class="icon-text">Employee ID: {{ $employee->emp_no }}</span>
                </p>
                <p class="opacity-90 mb-1">
                    <span class="icon-text">Hired: {{ \Carbon\Carbon::parse($employee->hire_date)->format('d.m.Y') }}
                        @if (!$stats['isCurrentEmployee'] && $stats['employmentEndDate'])
                            - {{ $stats['employmentEndDate']->format('d.m.Y') }}
                        @elseif($stats['isCurrentEmployee'])
                            - Present
                        @endif
                    </span>
                </p>
                <p class="opacity-90 mb-0">
                    <span class="icon-text">{{ $employee->gender === 'M' ? 'Male' : 'Female' }}</span>
                </p>
            </div>
            <div class="header-right">
                @if ($employee->currentDepartment->count() > 0)
                    <h4 class="mb-2">
                        <span class="badge bg-white">{{ $employee->currentDepartment->first()->dept_name }}</span>
                    </h4>
                @else
                    <h4 class="mb-2">
                        <span class="badge bg-secondary">Former Employee</span>
                    </h4>
                @endif
                @if ($employee->currentTitle)
                    <p class="opacity-90 mb-1">{{ $employee->currentTitle->title }}</p>
                @endif
                @if ($employee->currentSalary)
                    <h5 class="mb-0">${{ number_format($employee->currentSalary->salary) }} USD</h5>
                @endif
            </div>
            <div style="clear: both;"></div>
        </div>

        <div class="stats-container no-break">
            <div class="stats-card" style="background: #f093fb;">
                <h4>${{ number_format($stats['totalEarnings']) }}</h4>
                <p class="mb-0 opacity-90" style="font-size: 8px;">Total Earnings</p>
            </div>
            <div class="stats-card" style="background: #4facfe;">
                <h4>${{ number_format($stats['avgSalary']) }}</h4>
                <p class="mb-0 opacity-90" style="font-size: 8px;">Average Salary</p>
            </div>
            <div class="stats-card" style="background: #43e97b;">
                @if ($stats['totalDays'] > 0)
                    @if ($stats['yearsEmployed'] > 0)
                        <h4>
                            {{ (int) $stats['yearsEmployed'] }}
                            {{ (int) $stats['yearsEmployed'] == 1 ? 'year' : 'years' }}
                            @if ($stats['remainingDays'] > 0)
                                & {{ $stats['remainingDays'] }}
                                {{ $stats['remainingDays'] == 1 ? 'day' : 'days' }}
                            @endif
                        </h4>
                        <p class="mb-0 opacity-90" style="font-size: 7px;">({{ $stats['totalDays'] }} days total)</p>
                    @else
                        <h4>{{ $stats['totalDays'] }} {{ $stats['totalDays'] == 1 ? 'day' : 'days' }}</h4>
                        <p class="mb-0 opacity-90" style="font-size: 8px;">Employment Duration</p>
                    @endif
                @else
                    <h4>0 days</h4>
                    <p class="mb-0 opacity-90" style="font-size: 8px;">Employment Duration</p>
                @endif
            </div>
            <div class="stats-card" style="background: #667eea;">
                <h4>{{ $stats['departmentChanges'] }}</h4>
                <p class="mb-0 opacity-90" style="font-size: 8px;">Department Changes</p>
            </div>
            <div style="clear: both;"></div>
        </div>

        <div class="allow-break">
            <div class="card-header">
                <h5>Employment History</h5>
                <p class="mb-0 text-muted" style="font-size: 7px;">Table showing salary changes with corresponding
                    job titles and departments</p>
            </div>

            <table class="history-table">
                <thead>
                    <tr>
                        <th class="period-col">Period</th>
                        <th class="salary-col">Salary</th>
                        <th class="title-col">Job Title</th>
                        <th class="dept-col">Department</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($combinedHistory as $history)
                        <tr class="{{ $history->is_current ? 'current-row' : '' }}">
                            <td class="period-col">
                                <div class="fw-semibold">
                                    {{ \Carbon\Carbon::parse($history->from_date)->format('d.m.Y') }}
                                </div>
                                <div class="text-muted" style="font-size: 6px;">
                                    @if ($history->to_date !== '9999-01-01')
                                        to {{ \Carbon\Carbon::parse($history->to_date)->format('d.m.Y') }}
                                    @else
                                        to Present
                                    @endif
                                </div>
                            </td>
                            <td class="salary-col">
                                <span class="badge bg-salary">
                                    ${{ number_format($history->salary) }}
                                </span>
                                @if ($history->is_current)
                                    <div class="text-success fw-semibold" style="font-size: 6px;">Current Salary</div>
                                @endif
                            </td>
                            <td class="title-col">
                                <div class="fw-medium">{{ $history->title }}</div>
                                @if ($history->is_current)
                                    <div class="text-success" style="font-size: 6px;">
                                        <span class="current-indicator">Current Position</span>
                                    </div>
                                @endif
                            </td>
                            <td class="dept-col">
                                <span class="badge bg-department">
                                    {{ $history->department_name }}
                                </span>
                                @if ($history->is_current)
                                    <div class="text-success fw-semibold" style="font-size: 6px;">Current Department
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 15px; color: #6c757d;">
                                <div style="font-size: 8px;">No employment history available</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div style="margin-top: 15px; text-align: center; font-size: 7px; color: #666;">
            <p>Generated on {{ now()->format('d.m.Y H:i:s') }} | Employee Management System</p>
        </div>
    </div>
</body>

</html>
