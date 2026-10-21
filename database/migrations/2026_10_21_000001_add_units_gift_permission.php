<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        'units.gift' => 'Gift (transfer) land units from platform inventory to a user at no cost',
    ];

    // Gifting moves value without payment, so it is deliberately limited to
    // super_admin only. Grant it to other roles explicitly if ever needed.
    private array $roles = ['super_admin'];

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

        $roleIds = DB::table('roles')->whereIn('name', $this->roles)->pluck('id');

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
