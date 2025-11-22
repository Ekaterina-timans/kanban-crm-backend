<?php

    namespace App\Http\Controllers;

    use App\Models\Permission;
    use Illuminate\Http\JsonResponse;

    class PermissionController extends Controller
    {
        public function index(): JsonResponse
        {
            $permissions = Permission::select('id', 'name', 'description', 'sort_order')
                ->orderBy('sort_order')
                ->get();

            return response()->json($permissions);
        }
    }
