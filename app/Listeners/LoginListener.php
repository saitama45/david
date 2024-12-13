<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Auth\Events\Login;

class LoginListener
{
    public function handle(Login $event)
    {
        // Log audit for user logout
        $event->user->auditEvent('login');
    }
}
