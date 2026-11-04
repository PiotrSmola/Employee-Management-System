<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Support\EmployeeVisibility;
use Illuminate\Http\Request;

class IndexController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function index(Request $request)
    {
        return view('index', $this->dashboard->build(EmployeeVisibility::for($request->user())));
    }
}
