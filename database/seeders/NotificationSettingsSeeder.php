<?php

namespace Database\Seeders;

use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Database\Seeder;

class NotificationSettingsSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->select('id')->chunkById(500, function ($users) {
            $rows = [];

            foreach ($users as $user) {
                $rows[] = [
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            NotificationSetting::query()->upsert(
                $rows,
                ['user_id'],
                ['updated_at']
            );
        });
    }
}