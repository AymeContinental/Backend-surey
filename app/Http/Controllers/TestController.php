<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;

class TestController extends BaseController
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    public function index()
    {
        return response()->json(['message' => 'Acceso concedido']);
    }
}
