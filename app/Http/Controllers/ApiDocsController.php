<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\File;

class ApiDocsController extends Controller
{
    public function index(): View
    {
        return view('api-docs', [
            'openapi' => File::exists(base_path('docs/pbb-realtime-openapi.yaml'))
                ? File::get(base_path('docs/pbb-realtime-openapi.yaml'))
                : "openapi: 3.0.3\ninfo:\n  title: PBB Realtime API\n  version: 0.1.0\n",
        ]);
    }
}
