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
                'username' => 'portal.admin',
                'password' => Hash::make('Doyen#Ims2026!Secure'),
                'name' => 'Portal Administrator',
                'role' => 'admin',
                'is_active' => true,
            ]
        );
        $this->call(WarehouseSeeder::class);
    }
}
