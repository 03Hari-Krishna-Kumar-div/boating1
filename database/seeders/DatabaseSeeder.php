<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Boat;
use App\Models\Setting;
use App\Enums\UserRole;
use App\Enums\BoatStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Admin user ─────────────────────────────────────────────────────
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@brms.local',
            'password' => Hash::make('Hari@2003'),
            'role' => UserRole::ADMIN,
            'is_active' => true,
        ]);

        // ─── Single worker user ─────────────────────────────────────────────
        User::create([
            'name' => 'Worker',
            'email' => 'worker@brms.local',
            'password' => Hash::make('Hari@2003'),
            'role' => UserRole::WORKER,
            'is_active' => true,
            'last_activity_at' => now(),
        ]);

        // ─── Default boats (5 boats to start) ──────────────────────────────
        $boatNames = ['Speedster', 'Wave Rider', 'Ocean King', 'Sea Breeze', 'Storm Chaser'];

        foreach (range(1, 5) as $i) {
            Boat::create([
                'boat_number' => $i,
                'name' => $boatNames[$i - 1] ?? null,
                'status' => BoatStatus::AVAILABLE,
                'color_hex' => sprintf('#%06X', rand(0, 0xFFFFFF)),
            ]);
        }

        // ─── Default settings ──────────────────────────────────────────────
        $defaultSettings = [
            ['key' => 'rental_duration_minutes', 'value' => '45', 'updated_by' => $admin->id],
            ['key' => 'warning_minutes', 'value' => '5', 'updated_by' => $admin->id],
            ['key' => 'alarm_interval_seconds', 'value' => '1', 'updated_by' => $admin->id],
            ['key' => 'session_timeout_minutes', 'value' => '120', 'updated_by' => $admin->id],
        ];

        foreach ($defaultSettings as $setting) {
            Setting::create($setting);
        }
    }
}
