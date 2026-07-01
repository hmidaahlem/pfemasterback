<?php

namespace Database\Seeders;

use App\Models\Airport;
use App\Models\PointDeVente;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create roles
        $roles = [
            ['name' => 'SUPER_ADMIN', 'display_name' => 'Super Administrateur'],
            ['name' => 'RESPONSABLE_FB', 'display_name' => 'Responsable F&B'],
            ['name' => 'CHEF_CUISINE', 'display_name' => 'Chef de Cuisine'],
            ['name' => 'CHEF_MAGASIN', 'display_name' => 'Chef Magasin'],
            ['name' => 'RESPONSABLE_HYGIENE', 'display_name' => 'Responsable Hygiène'],
            ['name' => 'CAISSIER', 'display_name' => 'Caissier'],
            ['name' => 'RESPONSABLE_ACHAT', 'display_name' => 'Responsable Achat'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role['name']], $role);
        }

        // Create airports
        $enfidha = Airport::firstOrCreate(
            ['code' => 'NBE'],
            ['name' => 'Aéroport International Enfidha-Hammamet', 'code' => 'NBE']
        );

        $monastir = Airport::firstOrCreate(
            ['code' => 'MIR'],
            ['name' => 'Aéroport International de Monastir', 'code' => 'MIR']
        );

        // Create Super Admin
        $adminRole = Role::where('name', 'SUPER_ADMIN')->first();
        User::firstOrCreate(
            ['email' => 'admin@aeroserve.com'],
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'email' => 'admin@aeroserve.com',
                'password' => 'password',
                'role_id' => $adminRole->id,
                'status' => 'active',
            ]
        );

        $this->call(SampleDataSeeder::class);
    }
}
