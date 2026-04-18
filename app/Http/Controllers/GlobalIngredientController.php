<?php

namespace App\Http\Controllers;

use App\Models\GlobalIngredient;
use Illuminate\Http\JsonResponse;

class GlobalIngredientController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            GlobalIngredient::query()
                ->orderBy('name')
                ->get()
        );
    }
}
