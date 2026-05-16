<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class OperationsController extends Controller
{
    public function index(): View
    {
        return view('admin.app');
    }

    public function telemetry(): View
    {
        return view('admin.app');
    }
}
