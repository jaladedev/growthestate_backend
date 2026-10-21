<?php

use App\Models\AdminActionLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers — prefixed gift_ to avoid global function name collisions
// ─────────────────────────────────────────────────────────────────────────────

function giftSuperAdmin(): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'is_admin' => false]);
    $user->roles()->attach(Role::where('name', 'super_admin')->firstOrFail()->id, ['assigned_at' => now()]);

    return $user;
}

function giftRecipient(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'email_verified_at' => now(),
        'is_admin'          => false,
        'is_suspended'      => false,
        'screening_status'  => 'clear',
    ], $attrs));
}

function giftLand(int $available = 100): int
{
    $id = DB::table('lands')->insertGetId([
        'title'           => 'Gift Plot',
        'location'        => 'Lagos',
        'size'            => 500.0,
        'total_units'     => 100,
        'available_units' => $available,
        'is_available'    => $available > 0,
        'description'     => 'Test land.',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    DB::table('land_price_history')->insert([
        'land_id'             => $id,
        'price_per_unit_kobo' => 100_000,
        'price_date'          => now()->toDateString(),
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    return $id;
}

// ─────────────────────────────────────────────────────────────────────────────

describe('Admin unit gifting', function () {

    it('gifts units from platform inventory without touching any balance', function () {
        Notification::fake();

        $admin     = giftSuperAdmin();
        $recipient = giftRecipient(['balance_kobo' => 500, 'rewards_balance_kobo' => 700]);
        $landId    = giftLand(100);

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/users/{$recipient->id}/gift-units", [
                'land_id' => $landId,
                'units'   => 5,
                'reason'  => 'Welcome gift',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.units', 5)
            ->assertJsonPath('data.remaining_units', 95);

        $this->assertDatabaseHas('lands', ['id' => $landId, 'available_units' => 95]);
        $this->assertDatabaseHas('user_land', ['user_id' => $recipient->id, 'land_id' => $landId, 'units' => 5]);
        $this->assertDatabaseHas('purchases', [
            'user_id'                => $recipient->id,
            'land_id'                => $landId,
            'units'                  => 5,
            'total_amount_paid_kobo' => 0,
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id'     => $recipient->id,
            'type'        => 'gift',
            'units'       => 5,
            'amount_kobo' => 0,
            'status'      => 'completed',
        ]);

        // No money moved.
        $this->assertDatabaseHas('users', [
            'id' => $recipient->id, 'balance_kobo' => 500, 'rewards_balance_kobo' => 700,
        ]);

        $this->assertDatabaseHas('admin_action_logs', [
            'admin_id' => $admin->id,
            'action'   => 'units.gift',
        ]);
    });

    it('adds to an existing holding instead of replacing it', function () {
        Notification::fake();

        $admin     = giftSuperAdmin();
        $recipient = giftRecipient();
        $landId    = giftLand(100);

        foreach ([3, 4] as $n) {
            $this->actingAs($admin, 'api')
                ->postJson("/api/admin/users/{$recipient->id}/gift-units", [
                    'land_id' => $landId, 'units' => $n, 'reason' => 'Top-up gift',
                ])
                ->assertStatus(201);
        }

        $this->assertDatabaseHas('user_land', ['user_id' => $recipient->id, 'land_id' => $landId, 'units' => 7]);
        $this->assertDatabaseHas('lands', ['id' => $landId, 'available_units' => 93]);
    });

    it('rejects a gift larger than the available inventory and changes nothing', function () {
        $admin     = giftSuperAdmin();
        $recipient = giftRecipient();
        $landId    = giftLand(2);

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/users/{$recipient->id}/gift-units", [
                'land_id' => $landId, 'units' => 3, 'reason' => 'Too many units',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('units');

        $this->assertDatabaseHas('lands', ['id' => $landId, 'available_units' => 2]);
        $this->assertDatabaseMissing('user_land', ['user_id' => $recipient->id]);
    });

    it('marks the land unavailable when the gift takes the last units', function () {
        Notification::fake();

        $admin     = giftSuperAdmin();
        $recipient = giftRecipient();
        $landId    = giftLand(2);

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/users/{$recipient->id}/gift-units", [
                'land_id' => $landId, 'units' => 2, 'reason' => 'Final units',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('lands', ['id' => $landId, 'available_units' => 0, 'is_available' => false]);
    });

    it('requires land_id, a positive integer units, and a reason', function () {
        $admin     = giftSuperAdmin();
        $recipient = giftRecipient();

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/users/{$recipient->id}/gift-units", ['units' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['land_id', 'units', 'reason']);
    });

    it('refuses restricted, flagged, pending, suspended and admin recipients', function (array $attrs) {
        $admin     = giftSuperAdmin();
        $recipient = giftRecipient($attrs);
        $landId    = giftLand(10);

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/users/{$recipient->id}/gift-units", [
                'land_id' => $landId, 'units' => 1, 'reason' => 'Should be refused',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user');

        $this->assertDatabaseHas('lands', ['id' => $landId, 'available_units' => 10]);
    })->with([
        'blocked'   => [['screening_status' => 'blocked']],
        'flagged'   => [['screening_status' => 'flagged']],
        'pending'   => [['screening_status' => 'pending']],
        'suspended' => [['is_suspended' => true]],
        'admin'     => [['is_admin' => true]],
    ]);

    it('forbids staff without the units.gift permission', function () {
        $officer = User::factory()->create(['email_verified_at' => now(), 'is_admin' => false]);
        $officer->roles()->attach(Role::where('name', 'finance_officer')->firstOrFail()->id, ['assigned_at' => now()]);

        $recipient = giftRecipient();
        $landId    = giftLand(10);

        $this->actingAs($officer, 'api')
            ->postJson("/api/admin/users/{$recipient->id}/gift-units", [
                'land_id' => $landId, 'units' => 1, 'reason' => 'Not allowed',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'INSUFFICIENT_PERMISSIONS');

        $this->assertDatabaseHas('lands', ['id' => $landId, 'available_units' => 10]);
    });

    it('forbids regular users', function () {
        $user      = giftRecipient();
        $recipient = giftRecipient();
        $landId    = giftLand(10);

        $this->actingAs($user, 'api')
            ->postJson("/api/admin/users/{$recipient->id}/gift-units", [
                'land_id' => $landId, 'units' => 1, 'reason' => 'Not allowed',
            ])
            ->assertStatus(403);
    });
});
