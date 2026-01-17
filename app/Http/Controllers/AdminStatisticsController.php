<?php

namespace App\Http\Controllers;

use App\Services\AdminStatisticsService;
use Illuminate\Http\Request;

class AdminStatisticsController extends Controller
{
    private AdminStatisticsService $service;

    public function __construct(AdminStatisticsService $service)
    {
        $this->service = $service;
    }

    public function usersTotal()
    {
        return response()->json([
            'total' => $this->service->usersTotal()
        ]);
    }

    public function usersBlocked()
    {
        return response()->json([
            'blocked' => $this->service->usersBlocked()
        ]);
    }

    public function groupsTotal()
    {
        return response()->json([
            'total' => $this->service->groupsTotal()
        ]);
    }

    public function groupsActivity(Request $request)
    {
        $period = $this->service->resolvePeriod(
            $request->query('period'),
            $request->query('date_from'),
            $request->query('date_to')
        );

        return response()->json(
            $this->service->groupsActivity($period)
        );
    }

    public function inactiveGroups(Request $request)
    {
        $period = $this->service->resolvePeriod(
            $request->query('period'),
            $request->query('date_from'),
            $request->query('date_to')
        );

        return response()->json([
            'groups' => $this->service->inactiveGroups($period)
        ]);
    }
}
