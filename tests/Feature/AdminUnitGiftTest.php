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

// ─────────────────────────────────────────────────────────────────────────────
// Reversal
// ─────────────────────────────────────────────────────────────────────────────

function giftIt($test, User $admin, User $recipient, int $landId, int $units): string
{
    return $test->actingAs($admin, 'api')
        ->postJson("/api/admin/users/{$recipient->id}/gift-units", [
            'land_id' => $landId, 'units' => $units, 'reason' => 'Setup gift',
        ])
        ->assertStatus(201)
        ->json('data.reference');
}

function giftReverse($test, User $admin, User $recipient, string $ref, string $reason = 'Issued in error')
{
    return $test->actingAs($admin, 'api')
        ->postJson("/api/admin/users/{$recipient->id}/gifts/{$ref}/reverse", ['reason' => $reason]);
}

describe('Admin gift reversal', function () {

    it('returns the units to inventory and removes the holding', function () {
        Notification::fake();

        $admin     = giftSuperAdmin();
        $recipient = giftRecipient(['balance_kobo' => 500]);
        $landId    = giftLand(100);

        $ref = giftIt($this, $admin, $recipient, $landId, 5);

        giftReverse($this, $admin, $recipient, $ref)
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.units', 5)
            ->assertJsonPath('data.remaining_units', 100);

        $this->assertDatabaseHas('lands', ['id' => $landId, 'available_units' => 100]);
        $this->assertDatabaseMissing('user_land', ['user_id' => $recipient->id, 'land_id' => $landId]);
        $this->assertDatabaseHas('purchases', [
            'user_id' => $recipient->id, 'land_id' => $landId, 'units' => 0, 'status' => 'reversed',
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id'   => $recipient->id,
            'type'      => 'gift_reversal',
            'reference' => 'REV-' . $ref,
            'units'     => 5,
        ]);
        $this->assertDatabaseHas('admin_action_logs', ['admin_id' => $admin->id, 'action' => 'units.gift_reverse']);
        $this->assertDatabaseHas('users', ['id' => $recipient->id, 'balance_kobo' => 500]);
    });

    it('reverses only the gifted quantity when the user holds more', function () {
        Notification::fake();

        $admin     = giftSuperAdmin();
        $recipient = giftRecipient();
        $landId    = giftLand(100);

        $first = giftIt($this, $admin, $recipient, $landId, 5);
        giftIt($this, $admin, $recipient, $landId, 3);

        giftReverse($this, $admin, $recipient, $first)->assertStatus(200);

        $this->assertDatabaseHas('user_land', ['user_id' => $recipient->id, 'land_id' => $landId, 'units' => 3]);
        $this->assertDatabaseHas('purchases', ['user_id' => $recipient->id, 'units' => 3, 'status' => 'active']);
        $this->assertDatabaseHas('lands', ['id' => $landId, 'available_units' => 97]);
    });

    it('refuses to reverse the same gift twice', function () {
        Notification::fake();

        $admin     = giftSuperAdmin();
        $recipient = giftRecipient();
        $landId    = giftLand(100);
        $ref       = giftIt($this, $admin, $recipient, $landId, 5);

        giftReverse($this, $admin, $recipient, $ref)->assertStatus(200);

        giftReverse($this, $admin, $recipient, $ref)
            ->assertStatus(422)
            ->assertJsonValidationErrors('reference');

        $this->assertDatabaseHas('lands', ['id' => $landId, 'available_units' => 100]);
    });

    it('refuses when the user no longer holds the full gifted quantity', function () {
        Notification::fake();

        $admin     = giftSuperAdmin();
        $recipient = giftRecipient();
        $landId    = giftLand(100);
        $ref       = giftIt($this, $admin, $recipient, $landId, 5);

        // Simulate the user having sold 3 units since.
        DB::table('user_land')->where('user_id', $recipient->id)->update(['units' => 2]);

        giftReverse($this, $admin, $recipient, $ref)
            ->assertStatus(422)
            ->assertJsonValidationErrors('units');

        $this->assertDatabaseHas('user_land', ['user_id' => $recipient->id, 'units' => 2]);
        $this->assertDatabaseHas('lands', ['id' => $landId, 'available_units' => 95]);
        $this->assertDatabaseMissing('transactions', ['reference' => 'REV-' . $ref]);
    });

    it('refuses when the gifted units are listed on the marketplace', function () {
        Notification::fake();

        $admin     = giftSuperAdmin();
        $recipient = giftRecipient();
        $landId    = giftLand(100);
        $ref       = giftIt($this, $admin, $recipient, $landId, 5);

        DB::table('marketplace_listings')->insert([
            'seller_id'         => $recipient->id,
            'land_id'           => $landId,
            'units_for_sale'    => 4,
            'asking_price_kobo' => 100_000,
            'status'            => 'active',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        giftReverse($this, $admin, $recipient, $ref)
            ->assertStatus(422)
            ->assertJsonValidationErrors('units');

        $this->assertDatabaseHas('user_land', ['user_id' => $recipient->id, 'units' => 5]);
    });

    it('404-style rejects an unknown reference and another user\'s gift', function () {
        Notification::fake();

        $admin = giftSuperAdmin();
        $a     = giftRecipient();
        $b     = giftRecipient();
        $landId = giftLand(100);
        $ref   = giftIt($this, $admin, $a, $landId, 2);

        giftReverse($this, $admin, $a, 'GFT-does-not-exist')->assertStatus(422)->assertJsonValidationErrors('reference');
        giftReverse($this, $admin, $b, $ref)->assertStatus(422)->assertJsonValidationErrors('reference');

        $this->assertDatabaseHas('user_land', ['user_id' => $a->id, 'units' => 2]);
    });

    it('requires a reason', function () {
        Notification::fake();

        $admin     = giftSuperAdmin();
        $recipient = giftRecipient();
        $ref       = giftIt($this, $admin, $recipient, giftLand(10), 1);

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/users/{$recipient->id}/gifts/{$ref}/reverse", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    });

    it('lists a user\'s gifts and flags the reversed ones', function () {
        Notification::fake();

        $admin     = giftSuperAdmin();
        $recipient = giftRecipient();
        $landId    = giftLand(100);

        $keep     = giftIt($this, $admin, $recipient, $landId, 2);
        $reversed = giftIt($this, $admin, $recipient, $landId, 3);
        giftReverse($this, $admin, $recipient, $reversed)->assertStatus(200);

        $rows = collect(
            $this->actingAs($admin, 'api')
                ->getJson("/api/admin/users/{$recipient->id}/gifts")
                ->assertStatus(200)
                ->json('data')
        )->keyBy('reference');

        expect($rows)->toHaveCount(2);
        expect($rows[$keep]['reversed'])->toBeFalse();
        expect($rows[$reversed]['reversed'])->toBeTrue();
        expect($rows[$keep]['land_title'])->toBe('Gift Plot');
    });

    it('forbids staff without units.gift from listing or reversing', function () {
        $officer = User::factory()->create(['email_verified_at' => now(), 'is_admin' => false]);
        $officer->roles()->attach(Role::where('name', 'finance_officer')->firstOrFail()->id, ['assigned_at' => now()]);
        $recipient = giftRecipient();

        $this->actingAs($officer, 'api')
            ->getJson("/api/admin/users/{$recipient->id}/gifts")->assertStatus(403);

        $this->actingAs($officer, 'api')
            ->postJson("/api/admin/users/{$recipient->id}/gifts/GFT-x/reverse", ['reason' => 'Not allowed'])
            ->assertStatus(403);
    });
});
