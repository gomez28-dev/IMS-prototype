<?php

namespace App\Http\Controllers;

use App\Services\Executive\ExecutiveSummary;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExecutiveDashboardController extends Controller
{
    /**
     * Cross-module overview for the President, VP and an administrator.
     *
     * Read-only: the summary service never writes, and there are no POST routes
     * behind this page.
     */
    public function index(Request $request, ExecutiveSummary $summary): View
    {
        return view('executive.dashboard', [
            'summary' => $summary->all($request->user()),
        ]);
    }
}
