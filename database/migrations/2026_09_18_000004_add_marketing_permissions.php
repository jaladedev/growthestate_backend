<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        'marketing.view'   => 'View marketing campaigns and their stats',
        'marketing.manage' => 'Create, edit, send, and cancel marketing campaigns',
    ];

    public function up(): void
    {
        $now = now();

        $permissionIds = [];
        foreach ($this->permissions as $name => $label) {
            $existing = DB::table('permissions')->where('name', $name)->first();

            $permissionIds[$name] = $existing
                ? $existing->id
                : DB::table('permissions')->insertGetId([
                    'name'       => $name,
                    'label'      => $label,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        $superAdminId = DB::table('roles')->where('name', 'super_admin')->value('id');

        if ($superAdminId) {
            foreach ($permissionIds as $permissionId) {
                $exists = DB::table('permission_role')
                    ->where('role_id', $superAdminId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $exists) {
                    DB::table('permission_role')->insert([
                        'role_id'       => $superAdminId,
                        'permission_id' => $permissionId,
                        'created_at'    => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', array_keys($this->permissions))->delete();
    }
};
