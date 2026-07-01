<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed default Admin
        Admin::updateOrCreate(
            ['username' => 'admin'],
            [
                'password' => Hash::make('admin'),
                'name' => 'Administrator',
                'role' => 'admin',
                'is_active' => true,
            ]
        );
    }
}
