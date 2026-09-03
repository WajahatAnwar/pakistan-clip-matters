<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create roles first (if they don't exist)
        Role::firstOrCreate(['name' => 'superAdmin']);
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'manager']);
        Role::firstOrCreate(['name' => 'user']);


        // Create admin user
        $superAdmin = User::updateOrCreate(
            ['email' => 'clip.matters@digitalmatters.pk'],
            [
                'name' => 'Clip Matter Super Admin',
                'phone' => null,
                'password' => bcrypt('Cl!pM@tters2026'),
                'profile_picture' => null,
                'status' => true,
                'can_edit_profile' => true,
            ]
        );
        // Use syncRoles to ensure ONLY superAdmin role is assigned
        $superAdmin->syncRoles(['superAdmin']);


        $admin = User::updateOrCreate(
            ['email' => 'clip.admin@digitalmatters.pk'],
            [
                'name' => 'Clip Matter Admin',
                'phone' => null,
                'password' => bcrypt('Admin@2026'),
                'profile_picture' => null,
                'status' => true,
                'can_edit_profile' => true,
            ]
        );
        // Use syncRoles to ensure ONLY admin role is assigned
        $admin->syncRoles(['admin']);

        $manager = User::updateOrCreate(
            ['email' => 'clip.manager@digitalmatters.pk'],
            [
                'name' => 'Clip Matter Manager',
                'phone' => null,
                'password' => bcrypt('Manager@2026'),
                'profile_picture' => null,
                'status' => true,
                'can_edit_profile' => true,
            ]
        );
        // Use syncRoles to ensure ONLY manager role is assigned
        $manager->syncRoles(['manager']);

        // Create regular user
        $user = User::updateOrCreate(
            ['email' => 'clip.user@digitalmatters.pk'],
            [
                'name' => 'Clip Matter User',
                'phone' => null,
                'password' => bcrypt('User@2026'),
                'profile_picture' => null,
                'status' => true,
                'can_edit_profile' => true,
            ]
        );
        // Use syncRoles to ensure ONLY user role is assigned
        $user->syncRoles(['user']);
    }
}

