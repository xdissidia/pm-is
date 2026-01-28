<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\TaskPriority;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class TaskPrioritySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        TaskPriority::create(['label' => 'Very high', 'color' => '#FF0000', 'order' => 1]);
        TaskPriority::create(['label' => 'High', 'color' => '#E7590D', 'order' => 2]);
        TaskPriority::create(['label' => 'Medium', 'color' => '#EDD118', 'order' => 3]);
        TaskPriority::create(['label' => 'Low', 'color' => '#2771C2', 'order' => 4]);
        TaskPriority::create(['label' => 'Very low', 'color' => '#309E44', 'order' => 5]);

        // assign permissions to admin role
        $adminRole = Role::where('name', 'admin')->first();

        Permission::firstOrCreate(['name' => 'view task priority']);
        Permission::firstOrCreate(['name' => 'create task priority']);
        Permission::firstOrCreate(['name' => 'edit task priority']);
        Permission::firstOrCreate(['name' => 'delete task priority']);  
        Permission::firstOrCreate(['name' => 'restore task priority']);
        
        $adminRole->givePermissionTo('view task priority');
        $adminRole->givePermissionTo('create task priority');
        $adminRole->givePermissionTo('edit task priority');
        $adminRole->givePermissionTo('delete task priority');
        $adminRole->givePermissionTo('restore task priority');
    }
}
