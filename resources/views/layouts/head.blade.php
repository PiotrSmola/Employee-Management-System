<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle ?? 'Employee Management System' }}</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- dark/white theme script -->
    <script src="{{ asset('js/theme.js') }}"></script>

    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #920000 0%, #002a9d 100%);
        }

        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8fafc;
        }

        [data-bs-theme="dark"] body {
            background-color: #0f172a;
        }

        .navbar {
            background: var(--primary-gradient) !important;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .nav-link {
            color: rgb(255, 255, 255) !important;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transform: translateY(-2px);
        }

        .badge {
            font-weight: 500;
            padding: 0.5em 0.75em;
            font-size: 1.1rem;
        }

        .gradient-card-1 {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .gradient-card-2 {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .gradient-card-3 {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .gradient-card-4 {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }

        .table-primary {
            --bs-table-bg: #bcd9ff;
        }

        [data-bs-theme="dark"] .table-primary {
            --bs-table-bg: #001660;
            --bs-table-color: white;
        }

        .table {
            margin-bottom: 0;
            border-radius: 16px;
            overflow: hidden;
        }

        .table td,
        .table th {
            padding: 1.25rem 1.5rem;
            vertical-align: middle;
            border-color: #e2e8f0;
        }

        [data-bs-theme="dark"] .table td,
        [data-bs-theme="dark"] .table th {
            border-color: #334155;
        }

        .table td:first-child,
        .table th:first-child {
            padding-left: 2rem;
        }

        .table td:last-child,
        .table th:last-child {
            padding-right: 2rem;
        }

        .table thead th {
            padding-top: 1.5rem;
            padding-bottom: 1.5rem;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.875rem;
            letter-spacing: 0.05em;
        }

        .table-hover tbody tr:hover {
            background-color: rgba(37, 99, 235, 0.05);
            transition: all 0.2s ease;
        }

        tr:hover {
            transform: scale(1.02);
        }

        [data-bs-theme="dark"] .table-hover tbody tr:hover {
            background-color: rgba(59, 130, 246, 0.1);
        }

        .emp-number {
            background: linear-gradient(45deg, #3b82f6, #1d4ed8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 700;
        }

        .badge {
            font-weight: 500;
            padding: 0.5em 0.75em;
            font-size: 1.1rem;
        }

        .bg-department {
            background: linear-gradient(45deg, #6366f1, #8b5cf6) !important;
            color: white !important;
        }

        .bg-salary {
            background: linear-gradient(45deg, #059669, #10b981) !important;
            color: white !important;
        }

        footer {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%) !important;
            color: #e2e8f0 !important;
        }

        .btn {
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .progress {
            background-color: rgba(255, 255, 255, 0.3);
            border-radius: 10px;
        }

        .progress-bar {
            background: linear-gradient(90deg, #3b82f6, #1d4ed8);
            border-radius: 10px;
        }

        .card-header {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%) !important;
            border-bottom: 2px solid #e2e8f0;
            border-radius: 12px 12px 0 0 !important;
        }

        [data-bs-theme="dark"] .card-header {
            background: linear-gradient(135deg, #334155 0%, #475569 100%) !important;
            border-bottom-color: #475569;
        }

        .card-footer {
            background: #f8fafc !important;
            border-top: 1px solid #e2e8f0 !important;
        }

        [data-bs-theme="dark"] .card-footer {
            background: #1e293b !important;
            border-top-color: #334155 !important;
        }

        .pagination {
            display: flex;
            gap: 0.25rem;
            margin: 0;
            list-style: none;
            padding: 0;
        }

        .page-item {
            margin: 0;
        }

        .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 2.5rem;
            height: 2.5rem;
            padding: 0.5rem 0.75rem;
            color: #64748b;
            background-color: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            font-size: 1rem;
            transition: all 0.2s ease;
        }

        .page-link:hover {
            color: #3b82f6;
            background-color: #f8fafc;
            border-color: #cbd5e1;
            text-decoration: none;
        }

        .page-item.active .page-link {
            color: white;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border-color: #3b82f6;
            box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);
        }

        .page-item.disabled .page-link {
            color: #292929;
            background-color: #f8fafc;
            border-color: #f1f5f9;
            pointer-events: none;
            opacity: 0.7;
        }

        [data-bs-theme="dark"] .page-link {
            color: #94a3b8;
            background-color: #1e293b;
            border-color: #334155;
        }

        [data-bs-theme="dark"] .page-link:hover {
            color: #60a5fa;
            background-color: #334155;
            border-color: #475569;
        }

        [data-bs-theme="dark"] .page-item.disabled .page-link {
            color: #cacaca;
            background-color: #0f172a;
            border-border: #1e293b;
        }

        .bg-pink {
            background: linear-gradient(45deg, #ec4899, #be185d) !important;
            color: white !important;
        }

        /* Loading opacity */
        .opacity-50 {
            opacity: 0.5 !important;
            transition: opacity 0.3s ease;
        }

        th[style*="cursor: pointer"]:hover {
            background-color: rgba(0, 123, 255, 0.1) !important;
            transition: background-color 0.2s ease;
        }

        .spinner-border {
            width: 3rem;
            height: 3rem;
        }

        [data-bs-theme="dark"] .bg-pink {
            background: linear-gradient(45deg, #ec4899, #be185d) !important;
        }

        [data-bs-theme="dark"] .bg-primary {
            background: linear-gradient(45deg, #3b82f6, #1d4ed8) !important;
        }

        [data-bs-theme="dark"] .text-dark {
            color: #e2e8f0 !important;
        }

        .sortable-header {
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s ease;
        }

        .sortable-header:hover {
            background-color: rgba(0, 123, 255, 0.1) !important;
        }

        .sortable-header i {
            font-size: 0.8em;
        }

        [data-bs-theme="dark"] .sortable-header:hover {
            background-color: rgba(59, 130, 246, 0.2) !important;
        }

        .employee-row:hover {
            background-color: rgba(37, 99, 235, 0.08) !important;
            transform: scale(1.005);
            transition: all 0.2s ease;
        }

        .employee-row:hover .emp-number {
            text-shadow: 0 0 8px rgba(59, 130, 246, 0.3);
        }

        [data-bs-theme="dark"] .employee-row:hover {
            background-color: rgba(59, 130, 246, 0.15) !important;
        }

        .timeline {
            position: relative;
            padding-left: 30px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 12px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }

        .timeline-item {
            position: relative;
            margin-bottom: 20px;
            padding-bottom: 15px;
        }

        .timeline-item:last-child {
            margin-bottom: 0;
        }

        .timeline-marker {
            position: absolute;
            left: -24px;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid white;
            z-index: 1;
        }

        .timeline-content h6 {
            margin-bottom: 4px;
            font-weight: 600;
        }

        .timeline-item.current .timeline-marker {
            width: 16px;
            height: 16px;
            left: -26px;
        }

        .breadcrumb-item+.breadcrumb-item::before {
            content: "›";
        }

        [data-bs-theme="dark"] .timeline::before {
            background: #475569;
        }

        /* Responsive styles */
        @media (max-width: 768px) {

            .table td,
            .table th {
                padding: 0.875rem 1rem;
                font-size: 0.9rem;
            }

            .table td:first-child,
            .table th:first-child {
                padding-left: 1.25rem;
            }

            .table td:last-child,
            .table th:last-child {
                padding-right: 1.25rem;
            }

            .table thead th {
                padding-top: 1.25rem;
                padding-bottom: 1.25rem;
                font-size: 0.8rem;
            }

            .badge {
                font-size: 0.85rem;
                padding: 0.35em 0.6em;
            }

            .emp-number {
                font-size: 1.1rem;
            }

            tr:hover {
                transform: scale(1.01);
            }

            .pagination .page-link {
                min-width: 2rem;
                height: 2rem;
                padding: 0.25rem 0.5rem;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 576px) {

            .table td,
            .table th {
                padding: 0.5rem 0.75rem;
                font-size: 0.8rem;
                line-height: 1.3;
            }

            .table td:first-child,
            .table th:first-child {
                padding-left: 1rem;
            }

            .table td:last-child,
            .table th:last-child {
                padding-right: 1rem;
            }

            .table thead th {
                padding-top: 1rem;
                padding-bottom: 1rem;
                font-size: 0.7rem;
                line-height: 1.2;
            }

            .emp-number {
                font-size: 1rem;
                font-weight: 600;
            }

            tr:hover {
                transform: none;
            }

            .table td:nth-child(2) {
                padding: 0.5rem 0.5rem;
            }

            .table td:nth-child(2) .fw-semibold {
                font-size: 0.85rem;
                margin-bottom: 0.25rem;
            }

            .table td:nth-child(2) small {
                font-size: 0.65rem;
            }

            .table td:nth-child(3) .badge {
                font-size: 0.85rem;
                padding: 0.35em 0.55em;
            }

            .table td:nth-child(5) .badge {
                font-size: 0.85rem;
                padding: 0.55em 0.75em;
            }

            .pagination .page-link {
                min-width: 1.75rem;
                height: 1.75rem;
                padding: 0.25rem 0.4rem;
                font-size: 0.75rem;
            }

            .pagination .page-item:nth-child(n+6):nth-last-child(n+3) {
                display: none;
            }

            .card {
                border-radius: 8px;
                margin: 0 0.5rem;
            }

            .card-footer {
                padding: 0.75rem 1rem;
            }

            .card-footer .row {
                gap: 0.5rem;
            }

            .card-footer small {
                font-size: 0.7rem;
            }
        }

        @media (max-width: 480px) {

            .table td,
            .table th {
                padding: 0.375rem 0.5rem;
                font-size: 0.85rem;
            }

            .table td:first-child,
            .table th:first-child {
                padding-left: 0.75rem;
            }

            .table td:last-child,
            .table th:last-child {
                padding-right: 0.75rem;
            }

            .table thead th {
                padding-top: 0.75rem;
                padding-bottom: 0.75rem;
                font-size: 0.85rem;
            }

            .emp-number {
                font-size: 0.9rem;
            }

            .table td:nth-child(2) .fw-semibold {
                font-size: 0.9rem;
            }

            .table td:nth-child(2) small {
                font-size: 0.7rem;
            }

        }

        /* Custom Pagination */
        .pagination .page-link {
            border: none;
            background: none;
            color: inherit;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .pagination .page-link:hover {
            background-color: var(--bs-pagination-hover-bg);
            border-color: var(--bs-pagination-hover-border-color);
            color: var(--bs-pagination-hover-color);
        }

        .pagination .page-link:focus {
            box-shadow: var(--bs-pagination-focus-box-shadow);
        }

        .pagination .page-link[disabled] {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .pagination .page-item button.page-link {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Livewire loading states */
        [wire\:loading] .pagination {
            opacity: 0.7;
            pointer-events: none;
        }

        @media (max-width: 576px) {
            .pagination .page-item:nth-child(n+5):nth-last-child(n+3) {
                display: none;
            }

            .pagination .page-item.disabled:has(.page-link:contains("...")) {
                display: block;
            }
        }
    </style>

    @livewireStyles
</head>
