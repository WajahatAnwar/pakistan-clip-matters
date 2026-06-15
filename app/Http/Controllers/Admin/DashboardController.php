<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App\Http\Traits\ResponseTrait;
use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    use ResponseTrait;
    
    public function dashboard()
    {
        $user = auth()->user();
        $connectionStatus = $user->getConnectionStatus();
        
        return $this->inertiaResponse('Dashboard', [
            'connectionStatus' => $connectionStatus,
        ]);
    }
}
