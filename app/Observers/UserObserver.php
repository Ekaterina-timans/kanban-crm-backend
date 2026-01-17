<?php

namespace App\Observers;

use App\Models\NotificationSetting;
use App\Models\User;

// Автосоздание настроек при регистрации и не только
class UserObserver
{
    public function created(User $user): void
    {
        NotificationSetting::firstOrCreate([
            'user_id' => $user->id,

        ]);
    }
}
