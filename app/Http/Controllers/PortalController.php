<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PortalController extends Controller
{
    /**
     * Show the portal picker landing screen.
     */
    public function index(): View
    {
        return view('portal');
    }
}
