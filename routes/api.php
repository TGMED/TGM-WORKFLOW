<?php

use App\Http\Controllers\Api\HrAgentController;
use App\Http\Middleware\EnsureHrAgentToken;
use Illuminate\Support\Facades\Route;

/*
| Read-only API for the TGM HR Agent, at /api/agent/*. Switched off until
| HR_AGENT_API_TOKEN is set; every request must carry that token. Nothing
| here can change anything in Workflow.
*/
Route::prefix('agent')
    ->middleware(['throttle:120,1', EnsureHrAgentToken::class])
    ->controller(HrAgentController::class)
    ->group(function (): void {
        Route::get('health', 'health');
        Route::get('staff', 'staff');
        Route::get('leave-balances', 'leaveBalances');
        Route::get('attendance', 'attendance');
        Route::get('absences', 'absences');
        Route::get('reviews', 'reviews');
        Route::get('policies', 'policies');
        Route::get('policies/{policy}/file', 'policyFile');
        Route::get('holidays', 'holidays');
    });
