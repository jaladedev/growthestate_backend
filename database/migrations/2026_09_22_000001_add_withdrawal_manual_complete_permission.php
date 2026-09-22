<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        'withdrawals.manual_complete' => 'Mark a withdrawal as completed via manual/out-of-band bank transfer',
    ];

    // Roles that should be granted this permission in addition to super_admin
    // (which always gets every permission below).
    private array $additionalRoles = ['finance_officer'];

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

        $roleNames = array_merge(['super_admin'], $this->additionalRoles);
        $roleIds   = DB::table('roles')->whereIn('name', $roleNames)->pluck('id', 'name');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                $exists = DB::table('permission_role')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $exists) {
                    DB::table('permission_role')->insert([
                        'role_id'       => $roleId,
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
