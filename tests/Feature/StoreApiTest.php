<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_customer_and_issues_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Customer One',
            'email' => 'customer@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'role' => 'admin',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonStructure(['data' => ['user' => ['id'], 'token']]);

        $this->assertDatabaseHas('users', [
            'email' => 'customer@example.test',
            'role' => 'customer',
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Duplicate Customer',
            'email' => 'customer@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_login_and_logout_work_with_sanctum_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'customer@example.test',
            'password' => 'secret-password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertUnprocessable();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertOk();

        $token = $login->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        auth()->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_change_password_with_sanctum_guard(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'old-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check(
            'new-password',
            $user->fresh()->password
        ));
    }

    public function test_customer_cannot_manage_products(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/admin/products', [])
            ->assertForbidden();
    }

    public function test_public_catalog_only_returns_active_products_and_supports_filters(): void
    {
        $category = Category::create([
            'name' => 'Gaming Mouse',
            'slug' => 'gaming-mouse',
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Wireless Mouse',
            'slug' => 'wireless-mouse',
            'brand' => 'Example',
            'price' => 100,
            'stock' => 4,
            'is_active' => true,
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Inactive Mouse',
            'slug' => 'inactive-mouse',
            'price' => 20,
            'stock' => 0,
            'is_active' => false,
        ]);

        $reviewer = User::factory()->create();
        Review::create([
            'user_id' => $reviewer->id,
            'product_id' => Product::where('slug', 'wireless-mouse')->value('id'),
            'rating' => 5,
            'is_approved' => true,
        ]);

        $this->getJson('/api/v1/products?search=Wireless&category=gaming-mouse&in_stock=1&sort=price_asc&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.slug', 'wireless-mouse')
            ->assertJsonPath('data.0.reviews_count', 1)
            ->assertJsonPath('data.0.rating_avg', 5);

        $this->getJson('/api/v1/products/wireless-mouse')
            ->assertOk()
            ->assertJsonPath('data.slug', 'wireless-mouse');

        $this->getJson('/api/v1/categories/gaming-mouse')
            ->assertOk()
            ->assertJsonPath('data.slug', 'gaming-mouse');
    }

    public function test_admin_can_create_catalog_items_but_invalid_price_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $category = Category::create([
            'name' => 'Keyboards',
            'slug' => 'keyboards',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/products', [
                'category_id' => $category->id,
                'name' => 'Mechanical Keyboard',
                'slug' => 'mechanical-keyboard',
                'price' => 199.99,
                'stock' => 3,
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'mechanical-keyboard');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/products', [
                'category_id' => $category->id,
                'name' => 'Invalid Product',
                'price' => -1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/products', [
                'category_id' => $category->id,
                'name' => 'Invalid Stock',
                'price' => 20,
                'stock' => -1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock']);
    }

    public function test_admin_can_create_update_and_delete_categories(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/categories', ['name' => 'Gaming Desks'])
            ->assertCreated();
        $slug = $response->json('data.slug');

        $this->putJson('/api/v1/admin/categories/'.$slug, ['name' => 'Gaming Desks Pro'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Gaming Desks Pro');

        $this->deleteJson('/api/v1/admin/categories/'.$slug)
            ->assertOk();
    }

    public function test_admin_cannot_delete_category_that_still_has_products(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $category = Category::create([
            'name' => 'Gaming Desks',
            'slug' => 'gaming-desks',
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Desk',
            'slug' => 'gaming-desk',
            'price' => 100,
            'stock' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/admin/categories/gaming-desks')
            ->assertConflict();

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_checkout_uses_database_price_and_decrements_stock(): void
    {
        $user = User::factory()->create();
        $address = $user->addresses()->create([
            'recipient_name' => 'Customer One',
            'phone' => '081234567890',
            'province' => 'West Java',
            'city' => 'Bandung',
            'address_line' => 'Example Street 1',
        ]);
        $category = Category::create(['name' => 'Headsets', 'slug' => 'headsets']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Gaming Headset',
            'slug' => 'gaming-headset',
            'price' => '120.00',
            'stock' => 5,
            'is_active' => true,
        ]);
        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
                'payment_method' => 'manual_transfer',
                'total_amount' => 1,
                'price' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '240.00')
            ->assertJsonPath('data.total_amount', '240.00')
            ->assertJsonPath('data.payment_status', 'unpaid');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 3]);
        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'unit_price' => '120.00',
            'quantity' => 2,
        ]);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseHas('payments', ['order_id' => 1, 'status' => 'unpaid']);
    }

    public function test_checkout_rejects_insufficient_stock_without_changing_data(): void
    {
        $user = User::factory()->create();
        $address = $user->addresses()->create([
            'recipient_name' => 'Customer One',
            'phone' => '081234567890',
            'province' => 'West Java',
            'city' => 'Bandung',
            'address_line' => 'Example Street 1',
        ]);
        $category = Category::create(['name' => 'Controllers', 'slug' => 'controllers']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Game Controller',
            'slug' => 'game-controller',
            'price' => 50,
            'stock' => 1,
            'is_active' => true,
        ]);
        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
                'payment_method' => 'manual_transfer',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 1]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_customer_cannot_read_another_users_address(): void
    {
        $owner = User::factory()->create();
        $address = $owner->addresses()->create([
            'recipient_name' => 'Other Customer',
            'phone' => '081234567890',
            'province' => 'West Java',
            'city' => 'Bandung',
            'address_line' => 'Private address',
        ]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/addresses/'.$address->id)
            ->assertNotFound();
    }

    public function test_cart_rejects_non_positive_quantity(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Mousepads', 'slug' => 'mousepads']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Desk Mousepad',
            'slug' => 'desk-mousepad',
            'price' => 25,
            'stock' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_coupon_validation_and_admin_authorization(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/coupons', [
                'code' => 'SAVE10',
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'minimum_purchase' => 50,
                'maximum_discount' => 25,
                'is_active' => true,
            ])
            ->assertCreated();

        $this->postJson('/api/v1/coupons/validate', [
            'code' => 'save10',
            'subtotal' => 100,
        ])
            ->assertOk()
            ->assertJsonPath('data.discount_amount', '10.00');

        Coupon::create([
            'code' => 'EXPIRED',
            'discount_type' => 'fixed',
            'discount_value' => 20,
            'minimum_purchase' => 0,
            'used_count' => 0,
            'starts_at' => now()->subDays(3),
            'expires_at' => now()->subDay(),
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/coupons/validate', [
            'code' => 'EXPIRED',
            'subtotal' => 100,
        ])->assertUnprocessable();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/admin/coupons', [
                'code' => 'CUSTOMER',
                'discount_type' => 'fixed',
                'discount_value' => 10,
            ])
            ->assertForbidden();
    }

    public function test_order_cancellation_restores_stock_and_order_access_is_private(): void
    {
        $customer = User::factory()->create();
        $category = Category::create(['name' => 'Chairs', 'slug' => 'chairs']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Gaming Chair',
            'slug' => 'gaming-chair',
            'price' => 200,
            'stock' => 2,
            'is_active' => true,
        ]);
        $order = $customer->orders()->create([
            'order_number' => 'ORD-PRIVATE-001',
            'recipient_name' => 'Customer',
            'phone' => '081234567890',
            'province' => 'West Java',
            'city' => 'Bandung',
            'address_line' => 'Example Street',
            'subtotal' => 200,
            'shipping_cost' => 0,
            'discount_amount' => 0,
            'total_amount' => 200,
            'status' => 'pending',
            'payment_status' => 'unpaid',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => 200,
            'quantity' => 1,
            'subtotal' => 200,
        ]);
        $product->decrement('stock');

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/orders/'.$order->order_number.'/cancel')
            ->assertOk();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 2]);

        auth()->forgetGuards();
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/orders/'.$order->order_number)
            ->assertNotFound();
    }

    public function test_only_admin_can_verify_payments_and_advance_order_status(): void
    {
        $customer = User::factory()->create();
        $order = $customer->orders()->create([
            'order_number' => 'ORD-ADMIN-001',
            'recipient_name' => 'Customer',
            'phone' => '081234567890',
            'province' => 'West Java',
            'city' => 'Bandung',
            'address_line' => 'Example Street',
            'subtotal' => 100,
            'shipping_cost' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
            'status' => 'pending',
            'payment_status' => 'unpaid',
        ]);
        $order->payments()->create([
            'payment_method' => 'manual_transfer',
            'amount' => 100,
            'status' => 'unpaid',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->patchJson('/api/v1/admin/orders/'.$order->order_number.'/payment', ['status' => 'paid'])
            ->assertForbidden();

        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/admin/orders/'.$order->order_number.'/payment', ['status' => 'paid'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        $this->patchJson('/api/v1/admin/orders/'.$order->order_number.'/status', ['status' => 'processing'])
            ->assertOk();
        $this->patchJson('/api/v1/admin/orders/'.$order->order_number.'/status', ['status' => 'shipped'])
            ->assertOk();
        $this->patchJson('/api/v1/admin/orders/'.$order->order_number.'/status', ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }
}
