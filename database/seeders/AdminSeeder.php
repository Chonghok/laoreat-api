<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admins = [
            ['username' => 'laoreatadmin', 'email' => 'laoreatadmin@gmail.com', 'password' => '1234', 'role' => 'superadmin'],
            ['username' => 'laoreatmanager', 'email' => 'laoreatmanager@gmail.com', 'password' => '1111', 'role' => 'manager'],
            ['username' => 'laoreatoperator', 'email' => 'laoreatoperator@gmail.com', 'password' => '1111', 'role' => 'operator'],
        ];

        foreach ($admins as $a) {
            Admin::updateOrCreate(
                ['email' => $a['email']],
                [
                    'username' => $a['username'],
                    'password' => Hash::make($a['password']),
                    'role' => $a['role'],
                    'is_active' => 1,
                    'profile_url' => null,
                    'profile_public_id' => null,
                ]
            );
        }
    }
}
