<?php

namespace App\Http\Controllers;

use App\Services\StatisticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatisticsController extends Controller
{
    public function __construct(
        private readonly StatisticsService $statisticsService,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Statistics', [
            'statistics' => $this->statisticsService->getPlayerStatistics($request->user()->id),
        ]);
    }
}
