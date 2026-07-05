<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    private const MAX_CART_QUANTITY = 99;

    public function home(): View
    {
        $categories = Category::active()
            ->withCount(['products' => fn ($query) => $query->active()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('storefront.home', [
            'heroBanner' => Banner::active()->where('placement', 'home_hero')->orderBy('sort_order')->first(),
            'promoBanners' => Banner::active()->where('placement', 'home_promo')->orderBy('sort_order')->take(2)->get(),
            'categories' => Category::buildHierarchy($categories)->take(8),
            'featuredProducts' => Product::active()->featured()->with(['brand', 'category', 'specifications'])->latest()->take(8)->get(),
            'saleProducts' => Product::active()->where('is_sale', true)->with(['brand', 'category', 'specifications'])->latest()->take(4)->get(),
            'newProducts' => Product::active()->where('is_new', true)->with(['brand', 'category', 'specifications'])->latest()->take(4)->get(),
            'hitProducts' => Product::active()->where('is_hit', true)->with(['brand', 'category', 'specifications'])->latest()->take(4)->get(),
            'brands' => Brand::active()->withCount(['products' => fn ($query) => $query->active()])->orderBy('name')->take(8)->get(),
            'promotions' => Promotion::active()->orderBy('sort_order')->take(3)->get(),
        ]);
    }

    public function catalog(Request $request): View
    {
        $products = $this->catalogProductQuery($request)
            ->paginate(12)
            ->withQueryString();

        $categories = Category::active()->orderBy('sort_order')->orderBy('name')->get();

        return view('storefront.catalog', [
            'products' => $products,
            'categories' => Category::buildHierarchy($categories),
            'brands' => Brand::active()->orderBy('name')->get(),
            'catalogBanner' => Banner::active()->where('placement', 'catalog_top')->orderBy('sort_order')->first(),
        ]);
    }

    public function category(Request $request, Category $category): View
    {
        abort_unless($category->is_active, 404);

        $categories = Category::active()->orderBy('sort_order')->orderBy('name')->get();

        return view('storefront.category', [
            'category' => $category->load(['children', 'parent.parent']),
            'products' => $this->catalogProductQuery($request, allowCategoryFilter: false)
                ->where('category_id', $category->id)
                ->paginate(12)
                ->withQueryString(),
            'categories' => Category::buildHierarchy($categories),
            'brands' => Brand::active()->orderBy('name')->get(),
        ]);
    }

    public function product(Product $product): View
    {
        abort_unless($product->is_active, 404);

        $product->load(['brand', 'category', 'images', 'specifications']);

        return view('storefront.product', [
            'product' => $product,
            'relatedProducts' => Product::active()
                ->where('category_id', $product->category_id)
                ->whereKeyNot($product->getKey())
                ->with(['brand', 'category', 'specifications'])
                ->take(4)
                ->get(),
        ]);
    }

    public function cart(): View
    {
        return view('storefront.cart', $this->cartPayload());
    }

    public function addToCart(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->is_active, 404);

        if (! $product->isPurchasable()) {
            return back()->with('status', 'Цей товар зараз недоступний для замовлення.');
        }

        $quantity = min(
            max(1, (int) $request->integer('quantity', 1)),
            min($product->stock, self::MAX_CART_QUANTITY),
        );
        $cart = session('cart', []);
        $cart[$product->id] = min(($cart[$product->id] ?? 0) + $quantity, min($product->stock, self::MAX_CART_QUANTITY));

        session(['cart' => $cart]);

        return back()->with('status', 'Товар додано до кошика.');
    }

    public function updateCart(Request $request): RedirectResponse
    {
        $requestedCart = collect($request->input('quantities', []))
            ->mapWithKeys(fn ($quantity, $productId): array => [(int) $productId => max(0, min((int) $quantity, self::MAX_CART_QUANTITY))])
            ->filter()
            ->all();

        session(['cart' => $this->sanitizeCart($requestedCart)]);

        return back()->with('status', 'Кошик оновлено.');
    }

    public function removeFromCart(Product $product): RedirectResponse
    {
        $cart = session('cart', []);
        unset($cart[$product->id]);
        session(['cart' => $cart]);

        return back()->with('status', 'Товар прибрано з кошика.');
    }

    public function checkout(): View|RedirectResponse
    {
        $payload = $this->cartPayload();

        if ($payload['items']->isEmpty()) {
            return redirect()->route('cart')->with('status', 'Додайте товари перед оформленням.');
        }

        return view('storefront.checkout', $payload);
    }

    public function placeOrder(Request $request): RedirectResponse
    {
        $payload = $this->cartPayload();

        if ($payload['items']->isEmpty()) {
            return redirect()->route('cart')->with('status', 'Кошик порожній.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'delivery_method' => ['required', 'string', 'max:255'],
            'payment_method' => ['required', 'string', 'max:255'],
            'customer_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $order = DB::transaction(function () use ($validated, $payload): Order {
                $customer = Customer::updateOrCreate(
                    ['phone' => $validated['phone']],
                    [
                        'name' => $validated['name'],
                        'email' => $validated['email'] ?? null,
                        'city' => $validated['city'] ?? null,
                        'address' => $validated['address'] ?? null,
                    ],
                );

                $order = Order::create([
                    'customer_id' => $customer->id,
                    'customer_name' => $validated['name'],
                    'phone' => $validated['phone'],
                    'email' => $validated['email'] ?? null,
                    'total_amount' => $payload['total'],
                    'status' => 'new',
                    'delivery_method' => $validated['delivery_method'],
                    'payment_method' => $validated['payment_method'],
                    'customer_comment' => $validated['customer_comment'] ?? null,
                ]);

                foreach ($payload['items'] as $item) {
                    $product = Product::query()->lockForUpdate()->find($item['product']->id);

                    if (! $product?->isPurchasable() || $product->stock < $item['quantity']) {
                        throw new \RuntimeException('Cart product is no longer available.');
                    }

                    $order->items()->create([
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'quantity' => $item['quantity'],
                        'price' => $product->price,
                        'total' => $item['line_total'],
                    ]);

                    $product->decrement('stock', $item['quantity']);

                    if ($product->fresh()->stock <= 0) {
                        $product->update(['stock_status' => 'out_of_stock']);
                    }
                }

                return $order;
            });
        } catch (\RuntimeException) {
            return redirect()->route('cart')->with('status', 'Кошик оновлено: частина товарів більше недоступна.');
        }

        session()->forget('cart');

        return redirect()->route('checkout.thank-you', $order)->with('status', 'Замовлення прийнято.');
    }

    public function thankYou(Order $order): View
    {
        return view('storefront.thank-you', ['order' => $order->load('items')]);
    }

    public function deliveryPayment(): View
    {
        return view('storefront.pages.delivery-payment');
    }

    public function contacts(): View
    {
        return view('storefront.pages.contacts');
    }

    public function about(): View
    {
        return view('storefront.pages.about');
    }

    /**
     * @return array{items: Collection<int, array{product: Product, quantity: int, line_total: float}>, subtotal: float, total: float}
     */
    private function cartPayload(): array
    {
        $cart = $this->sanitizeCart(session('cart', []));
        session(['cart' => $cart]);

        $products = Product::purchasable()->whereIn('id', array_keys($cart))->with(['brand', 'category'])->get();

        $items = $products->map(fn (Product $product): array => [
            'product' => $product,
            'quantity' => min((int) $cart[$product->id], $product->stock),
            'line_total' => (float) $product->price * min((int) $cart[$product->id], $product->stock),
        ]);

        $subtotal = $items->sum('line_total');

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ];
    }

    private function catalogProductQuery(Request $request, bool $allowCategoryFilter = true): Builder
    {
        $search = trim((string) $request->input('q', ''));

        $query = Product::active()
            ->with(['brand', 'category', 'specifications'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', '%' . $search . '%')
                ->orWhere('sku', 'like', '%' . $search . '%')
                ->orWhere('short_description', 'like', '%' . $search . '%')))
            ->when($allowCategoryFilter && $request->filled('category'), fn (Builder $query) => $query->whereRelation('category', 'slug', (string) $request->input('category')))
            ->when($request->filled('brand'), fn (Builder $query) => $query->whereRelation('brand', 'slug', (string) $request->input('brand')))
            ->when($request->boolean('sale'), fn (Builder $query) => $query->where('is_sale', true))
            ->when($request->boolean('new'), fn (Builder $query) => $query->where('is_new', true))
            ->when($request->boolean('hit'), fn (Builder $query) => $query->where('is_hit', true));

        match ($request->input('stock')) {
            'available' => $query->where('stock', '>', 0)->whereIn('stock_status', ['in_stock', 'low_stock']),
            'preorder' => $query->where('stock_status', 'preorder'),
            'out' => $query->where(fn (Builder $query) => $query
                ->where('stock', '<=', 0)
                ->orWhere('stock_status', 'out_of_stock')),
            default => null,
        };

        match ($request->input('sort', 'new')) {
            'cheap' => $query->orderBy('price')->orderByDesc('created_at'),
            'expensive' => $query->orderByDesc('price')->orderByDesc('created_at'),
            'popular' => $query->orderByDesc('is_hit')->orderByDesc('is_sale')->orderByDesc('created_at'),
            default => $query->latest(),
        };

        return $query;
    }

    /**
     * @param  array<int|string, int|string>  $cart
     * @return array<int, int>
     */
    private function sanitizeCart(array $cart): array
    {
        $requested = collect($cart)
            ->mapWithKeys(fn ($quantity, $productId): array => [(int) $productId => max(0, min((int) $quantity, self::MAX_CART_QUANTITY))])
            ->filter();

        if ($requested->isEmpty()) {
            return [];
        }

        return Product::purchasable()
            ->whereIn('id', $requested->keys())
            ->pluck('stock', 'id')
            ->mapWithKeys(fn (int $stock, int $productId): array => [
                $productId => min($requested[$productId], $stock, self::MAX_CART_QUANTITY),
            ])
            ->filter()
            ->all();
    }
}
