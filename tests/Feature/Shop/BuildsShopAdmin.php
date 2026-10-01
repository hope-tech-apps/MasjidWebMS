<?php

namespace Tests\Feature\Shop;

use App\Models\CartItem;
use App\Models\Masjid;
use App\Models\MasjidUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\ProductVariant;
use App\Models\Service;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;

/**
 * What the shop's ADMIN tests share (shop slice B2): the office's logins, the URLs, and the
 * records a paid basket leaves, built by hand. Use it WITH Tests\Feature\Cart\BuildsBaskets and
 * Tests\Feature\Shop\BuildsShop, which supply the organisation (`org()`, `shopOrg()`), the
 * catalogue (`product()`, `variant()`, `sizeOf()`) and a held unit (`pendingOrderHolding()`).
 *
 * Every record is built UNBOUND with an explicit masjid_id, so each builder first forgets the tenant:
 * an admin request leaves its organisation bound for the rest of the test, and a bound tenant
 * overrides the masjid_id a creating hook is given.
 */
trait BuildsShopAdmin
{
    protected function bootShopAdminTest(): void
    {
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(RolesAndPermissionsSeeder::class);

        app(TenantContext::class)->forgetTenant();
    }

    /** A MasjidAdmin of this organisation, holding the bridged `masjid-admin` role (all eight permissions). */
    protected function adminOf(Masjid $org): User
    {
        app(TenantContext::class)->forgetTenant();

        $user = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        MasjidUser::create([
            'masjid_id' => $org->id, 'user_id' => $user->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        return $user->fresh();
    }

    /**
     * An admin with NO role, holding only the named permissions: the office login the permission
     * gate has to tell apart from the full one.
     *
     * @param  list<string>  $permissions
     */
    protected function adminHolding(Masjid $org, array $permissions): User
    {
        $user = $this->adminOf($org);
        $user->syncRoles([]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    protected function shopUrl(Masjid $org, string $path = ''): string
    {
        return '/api/admin/masjids/' . $org->id . '/shop' . $path;
    }

    /**
     * A PAID basket line of `$variant`, as settlement leaves it: the order (with its buyer), the
     * line and the product sale. The sale's names and price are the snapshot, taken from the
     * catalogue as it stands NOW.
     *
     * @param  array<string,mixed>  $order  overrides for the order; `charge_flag` is set beside it
     * @param  array<string,mixed>  $sale  overrides for the sale
     */
    protected function paidSale(Masjid $org, ProductVariant $variant, array $order = [], array $sale = []): ProductSale
    {
        app(TenantContext::class)->forgetTenant();

        $product = Product::withoutMasjidScope()->withTrashed()->findOrFail($variant->product_id);
        $quantity = (int) ($sale['quantity'] ?? 1);
        $unit = (int) ($variant->price_minor ?? $product->base_price_minor);

        $row = Order::withoutMasjidScope()->create(array_merge([
            'masjid_id' => $org->id,
            'uuid' => (string) Str::uuid(),
            'order_number' => strtoupper(Str::random(8)),
            'status' => Order::STATUS_PAID,
            'buyer_name' => 'Zaynab Buyer',
            'buyer_email' => 'zaynab@example.org',
            'buyer_phone' => '+1 555 010 0100',
            'total_minor' => $unit * $quantity,
            'currency' => 'usd',
            'charge_account_id' => 'acct_' . uniqid(),
            'paid_at' => now(),
        ], Arr::except($order, ['charge_flag'])));

        if (isset($order['charge_flag'])) {
            $row->forceFill(['charge_flag' => $order['charge_flag'], 'charge_flagged_at' => now()])->save();
        }

        $line = OrderItem::withoutMasjidScope()->create([
            'order_id' => $row->id,
            'masjid_id' => $org->id,
            'buyable_type' => CartItem::TYPE_PRODUCT,
            'buyable_id' => $variant->id,
            'recorded_as' => CartItem::RECORDED_AS_SALE,
            'label' => $product->name . ' (' . $variant->label . ')',
            'quantity' => $quantity,
            'unit_amount_minor' => $unit,
            'total_minor' => $unit * $quantity,
            'currency' => 'usd',
        ]);

        return ProductSale::withoutMasjidScope()->create(array_merge([
            'masjid_id' => $org->id,
            'order_id' => $row->id,
            'order_item_id' => $line->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'product_name' => $product->name,
            'variant_label' => $variant->label,
            'quantity' => $quantity,
            'unit_minor' => $unit,
            'total_minor' => $unit * $quantity,
            'oversold' => false,
        ], Arr::except($sale, ['quantity'])));
    }

    /**
     * A picture row of any model, with its file on the `public` disk (the way Spatie writes one). The
     * test fakes that disk once, in its own setUp: faking it here would wipe the earlier pictures.
     */
    protected function pictureOf(string $modelType, int $modelId, string $collection = Product::IMAGES, int $order = 1): Media
    {
        $media = new Media;
        $media->model_type = $modelType;
        $media->model_id = $modelId;
        $media->uuid = (string) Str::uuid();
        $media->collection_name = $collection;
        $media->name = 'picture';
        $media->file_name = 'picture-' . Str::random(6) . '.png';
        $media->mime_type = 'image/png';
        $media->disk = 'public';
        $media->size = 12;
        $media->manipulations = [];
        $media->custom_properties = [];
        $media->generated_conversions = [];
        $media->responsive_images = [];
        $media->order_column = $order;
        $media->save();

        Storage::disk('public')->put($media->getPathRelativeToRoot(), 'imagebytes');

        return $media;
    }

    /** A picture of this product, as an upload would have stored it. */
    protected function imageOf(Product $product, int $order = 1): Media
    {
        return $this->pictureOf(Product::class, (int) $product->id, Product::IMAGES, $order);
    }

    /** A service of an organisation, whose id can be made to collide with a product's. */
    protected function serviceOf(Masjid $org): Service
    {
        app(TenantContext::class)->forgetTenant();

        return Service::create([
            'masjid_id' => $org->id, 'title' => 'Service', 'summary' => 'x', 'description' => 'x', 'text' => 'x',
        ]);
    }
}
