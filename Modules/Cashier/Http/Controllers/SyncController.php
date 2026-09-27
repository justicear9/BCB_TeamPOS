<?php

namespace Modules\Cashier\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SyncController extends Controller
{
    public function locations()
    {
        return response()->json(['data' => []]);
    }

    public function changes(Request $request)
    {
        return response()->json(['data' => []]);
    }

    public function store(Request $request)
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }
}
