<?php

namespace Tests\Feature;

use App\Models\StoreInvitation;
use App\Models\User;
use App\Notifications\StaffInviteMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StaffPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private array $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->owner = $this->register('owner@a.test', 'Shop A');
    }

    private function register(string $email, string $store): array
    {
        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'Owner', 'email' => $email, 'password' => 'secret-pass', 'store_name' => $store,
        ])->assertCreated();

        return ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(array $m): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$m['token'], 'X-Store-Id' => (string) $m['store_id']]);
    }

    private function guest(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    /** Invite and capture the emailed token (the DB only stores its hash). */
    private function invite(string $email, array $permissions): string
    {
        $this->as($this->owner)->postJson('/api/admin/staff/invitations', ['email' => $email, 'permissions' => $permissions])->assertCreated();

        $token = null;
        Mail::assertQueued(StaffInviteMail::class, function (StaffInviteMail $m) use ($email, &$token) {
            if (! $m->hasTo($email)) {
                return false;
            }
            $token = substr($m->url, strrpos($m->url, '/') + 1);

            return true;
        });

        return $token;
    }

    private function joinAsStaff(array $permissions, string $email = 'staff@a.test'): array
    {
        $token = $this->invite($email, $permissions);
        $res = $this->guest()->postJson("/api/admin/invitations/{$token}/accept", ['name' => 'Sam', 'password' => 'staff-pass-1'])->assertOk();

        return ['token' => $res['token'], 'store_id' => $res['store_id'], 'user_id' => $res['user']['id']];
    }

    public function test_invite_email_and_accept_creates_staff_account(): void
    {
        $token = $this->invite('staff@a.test', ['orders', 'products']);
        $this->assertDatabaseMissing('store_invitations', ['token_hash' => $token]);           // only the hash is stored
        $this->assertDatabaseHas('store_invitations', ['token_hash' => hash('sha256', $token)]);
        Mail::assertQueued(StaffInviteMail::class, fn ($m) => str_contains($m->render(), '订单、商品与分类'));

        $this->guest()->getJson("/api/admin/invitations/{$token}")->assertOk()
            ->assertJsonPath('store_name', 'Shop A')->assertJsonPath('has_account', false)
            ->assertJsonPath('permissions', ['订单', '商品与分类']);

        $res = $this->guest()->postJson("/api/admin/invitations/{$token}/accept", ['name' => 'Sam', 'password' => 'staff-pass-1'])->assertOk();
        $res->assertJsonPath('stores.0.role', 'staff')->assertJsonPath('stores.0.permissions', ['orders', 'products']);

        // Link is single-use
        $this->guest()->postJson("/api/admin/invitations/{$token}/accept", ['name' => 'X', 'password' => 'another-pass'])->assertNotFound();
        $this->guest()->getJson('/api/admin/invitations/not-a-real-token')->assertNotFound();
    }

    public function test_permissions_are_enforced_on_the_api(): void
    {
        $staff = $this->joinAsStaff(['orders']);

        $this->as($staff)->getJson('/api/admin/orders')->assertOk();
        foreach (['/api/admin/products', '/api/admin/customers', '/api/admin/analytics', '/api/admin/discounts',
                  '/api/admin/theme', '/api/admin/menus', '/api/admin/settings/store', '/api/admin/domains', '/api/admin/staff'] as $url) {
            $this->as($staff)->getJson($url)->assertForbidden();
        }
        $this->as($staff)->postJson('/api/admin/products', ['title' => 'X'])->assertForbidden();
        $this->as($staff)->putJson('/api/admin/settings/store', ['name' => 'Hacked', 'currency' => 'USD', 'timezone' => 'UTC'])->assertForbidden();

        // Dashboard only contains what they may see
        $dash = $this->as($staff)->getJson('/api/admin/dashboard')->assertOk()->json();
        $this->assertArrayHasKey('orders', $dash);
        $this->assertArrayNotHasKey('revenue', $dash);
        $this->assertArrayNotHasKey('customers', $dash);

        // Online-store staff can use the collection list (theme/menu editors) but not edit collections
        $designer = $this->joinAsStaff(['online_store'], 'designer@a.test');
        $this->as($designer)->getJson('/api/admin/collections-list')->assertOk();
        $this->as($designer)->getJson('/api/admin/theme')->assertOk();
        $this->as($designer)->postJson('/api/admin/collections', ['title' => 'X'])->assertForbidden();
    }

    public function test_owner_changes_permissions_and_removal_is_immediate(): void
    {
        $staff = $this->joinAsStaff(['orders']);
        $this->as($staff)->getJson('/api/admin/products')->assertForbidden();

        $this->as($this->owner)->putJson("/api/admin/staff/{$staff['user_id']}", ['permissions' => ['orders', 'products']])->assertOk();
        $this->as($staff)->getJson('/api/admin/products')->assertOk();

        $this->as($this->owner)->deleteJson("/api/admin/staff/{$staff['user_id']}")->assertOk();
        $this->as($staff)->getJson('/api/admin/orders')->assertForbidden();   // same token, no longer a member
    }

    public function test_only_the_owner_manages_staff_and_owner_is_protected(): void
    {
        $staff = $this->joinAsStaff(['orders', 'products', 'customers', 'marketing', 'analytics', 'online_store', 'settings']);

        // Even with every permission, staff can't manage staff
        $this->as($staff)->getJson('/api/admin/staff')->assertForbidden();
        $this->as($staff)->postJson('/api/admin/staff/invitations', ['email' => 'x@a.test', 'permissions' => ['orders']])->assertForbidden();

        $ownerId = User::where('email', 'owner@a.test')->value('id');
        $this->as($this->owner)->putJson("/api/admin/staff/{$ownerId}", ['permissions' => ['orders']])->assertStatus(422);
        $this->as($this->owner)->deleteJson("/api/admin/staff/{$ownerId}")->assertStatus(422);

        // Validation
        $this->as($this->owner)->postJson('/api/admin/staff/invitations', ['email' => 'y@a.test', 'permissions' => []])->assertStatus(422);
        $this->as($this->owner)->postJson('/api/admin/staff/invitations', ['email' => 'y@a.test', 'permissions' => ['root']])->assertStatus(422);
        $this->as($this->owner)->postJson('/api/admin/staff/invitations', ['email' => 'staff@a.test', 'permissions' => ['orders']])->assertStatus(422);
    }

    public function test_existing_user_must_prove_password_and_gets_both_stores(): void
    {
        $other = $this->register('owner@b.test', 'Shop B');
        $token = $this->invite('owner@b.test', ['marketing']);

        $this->guest()->getJson("/api/admin/invitations/{$token}")->assertJsonPath('has_account', true);
        $this->guest()->postJson("/api/admin/invitations/{$token}/accept", ['password' => 'wrong-password'])->assertStatus(422);

        $res = $this->guest()->postJson("/api/admin/invitations/{$token}/accept", ['password' => 'secret-pass'])->assertOk();
        $this->assertCount(2, $res['stores']);
        $roles = collect($res['stores'])->pluck('role', 'name');
        $this->assertSame('owner', $roles['Shop B']);
        $this->assertSame('staff', $roles['Shop A']);

        // Still the owner of B, and only marketing in A
        $this->as(['token' => $res['token'], 'store_id' => $other['store_id']])->getJson('/api/admin/staff')->assertOk();
        $this->as(['token' => $res['token'], 'store_id' => $this->owner['store_id']])->getJson('/api/admin/discounts')->assertOk();
        $this->as(['token' => $res['token'], 'store_id' => $this->owner['store_id']])->getJson('/api/admin/orders')->assertForbidden();
    }

    public function test_expired_resent_and_cancelled_invitations(): void
    {
        $token = $this->invite('late@a.test', ['orders']);
        StoreInvitation::withoutGlobalScope('store')->update(['expires_at' => now()->subMinute()]);
        $this->guest()->getJson("/api/admin/invitations/{$token}")->assertNotFound();

        // Resend issues a new link; the old one stays dead
        $id = StoreInvitation::withoutGlobalScope('store')->value('id');
        Mail::fake();
        $this->as($this->owner)->postJson("/api/admin/staff/invitations/{$id}/resend")->assertOk();
        $newToken = null;
        Mail::assertQueued(StaffInviteMail::class, function ($m) use (&$newToken) {
            $newToken = substr($m->url, strrpos($m->url, '/') + 1);

            return true;
        });
        $this->assertNotSame($token, $newToken);
        $this->guest()->getJson("/api/admin/invitations/{$newToken}")->assertOk();

        $this->as($this->owner)->deleteJson("/api/admin/staff/invitations/{$id}")->assertOk();
        $this->guest()->getJson("/api/admin/invitations/{$newToken}")->assertNotFound();
    }

    public function test_staff_management_is_store_isolated(): void
    {
        $staff = $this->joinAsStaff(['orders']);
        $b = $this->register('owner@b.test', 'Shop B');

        $this->as($b)->getJson('/api/admin/staff')->assertOk()->assertJsonCount(1, 'members');   // only B's owner
        $this->as($b)->putJson("/api/admin/staff/{$staff['user_id']}", ['permissions' => ['settings']])->assertNotFound();
        $this->as($b)->deleteJson("/api/admin/staff/{$staff['user_id']}")->assertNotFound();
        $id = StoreInvitation::withoutGlobalScope('store')->value('id');
        $this->as($b)->deleteJson("/api/admin/staff/invitations/{$id}")->assertNotFound();

        // Staff of A can't reach B by switching the header
        $this->as(['token' => $staff['token'], 'store_id' => $b['store_id']])->getJson('/api/admin/orders')->assertForbidden();
    }
}
