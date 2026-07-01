<?php

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

if (!function_exists('saveLog')) {

    function saveLog($action, $module, $data = null, $userId = null)
    {
        try {
            AuditLog::create([
                //'user_id' => Auth::id(),
                'user_id' => $userId ?? Auth::id(),
                'action'  => $action,
                'module'  => $module,
                'data'    => $data ? json_encode($data) : null
            ]);
        } catch (\Exception $e) {
            // optional: store in laravel.log
            \Log::error('Log failed: '.$e->getMessage());
        }
    }
}