<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CommerceSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\ProductPricingService;
use App\Support\Database\PortableTextSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class StorefrontController extends Controller
{
    private const CHECKOUT_TOKEN_SESSION_KEY = 'storefront_checkout_token';

    private const CHECKOUT_PROCESSING_TOKEN_SESSION_KEY = 'storefront_checkout_processing_token';

    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly ProductPricingService $pricingService,
    ) {}

    public function home(): View
    {
        $productRelations = $this->checkoutService->productRelations();

        $categories = Category::active()
            ->withCount(['products' => fn ($query) => $query->active()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return view('storefront.home', [
            'heroBanner' => Banner::active()->where('placement', 'home_hero')->orderBy('sort_order')->orderBy('id')->first(),
            'promoBanners' => Banner::active()->where('placement', 'home_promo')->orderBy('sort_order')->orderBy('id')->take(2)->get(),
            'categories' => Category::buildHierarchy($categories)->take(8),
            'featuredProducts' => Product::active()->featured()->with($productRelations)->latest()->orderByDesc('id')->take(8)->get(),
            'saleProducts' => Product::active()->where('is_sale', true)->with($productRelations)->latest()->orderByDesc('id')->take(4)->get(),
            'newProducts' => Product::active()->where('is_new', true)->with($productRelations)->latest()->orderByDesc('id')->take(4)->get(),
            'hitProducts' => Product::active()->where('is_hit', true)->with($productRelations)->latest()->orderByDesc('id')->take(4)->get(),
            'brands' => Brand::active()->withCount(['products' => fn ($query) => $query->active()])->orderBy('name')->orderBy('id')->take(8)->get(),
            'promotions' => Promotion::active()->orderBy('sort_order')->orderBy('id')->take(3)->get(),
        ]);
    }

    public function catalog(Request $request): View
    {
        $products = $this->catalogProductQuery($request)
            ->paginate(12)
            ->withQueryString();

        $categories = Category::active()->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();

        return view('storefront.catalog', [
            'products' => $products,
            'categories' => Category::buildHierarchy($categories),
            'brands' => Brand::active()->orderBy('name')->orderBy('id')->get(),
            'catalogBanner' => Banner::active()->where('placement', 'catalog_top')->orderBy('sort_order')->orderBy('id')->first(),
        ]);
    }

    public function category(Request $request, Category $category): View
    {
        abort_unless($category->is_active, 404);

        $categories = Category::active()->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();

        return view('storefront.category', [
            'category' => $category->load(['children', 'parent.parent']),
            'products' => $this->catalogProductQuery($request, allowCategoryFilter: false)
                ->where('category_id', $category->id)
                ->paginate(12)
                ->withQueryString(),
            'categories' => Category::buildHierarchy($categories),
            'brands' => Brand::active()->orderBy('name')->orderBy('id')->get(),
        ]);
    }

    public function product(Product $product): View
    {
        abort_unless($product->is_active, 404);

        $product->load(array_values(array_unique(array_merge(
            ['images'],
            $this->checkoutService->productRelations(),
        ))));

        return view('storefront.product', [
            'product' => $product,
            'relatedProducts' => Product::active()
                ->where('category_id', $product->category_id)
                ->whereKeyNot($product->getKey())
                ->with($this->checkoutService->productRelations())
                ->orderBy('id')
                ->take(4)
                ->get(),
        ]);
    }

    public function cart(): View
    {
        $payload = $this->checkoutService->cartPayload(session('cart', []));
        session(['cart' => $payload['cart']]);

        return view('storefront.cart', $payload);
    }

    public function addToCart(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->is_active, 404);

        $result = $this->checkoutService->addToCart(
            $product,
            (int) $request->integer('quantity', 1),
            session('cart', []),
            $request->filled('variant_id') ? (int) $request->integer('variant_id') : null,
        );

        session(['cart' => $result['cart']]);

        return back()->with('status', $result['status']);
    }

    public function updateCart(Request $request): RedirectResponse
    {
        $requestedCart = collect($request->input('quantities', []))
            ->mapWithKeys(fn ($quantity, $cartKey): array => [(string) $cartKey => (int) $quantity])
            ->filter()
            ->all();

        session(['cart' => $this->checkoutService->sanitizeCart($requestedCart)]);

        return back()->with('status', 'Кошик оновлено.');
    }

    public function removeFromCart(Product $product): RedirectResponse
    {
        $cart = $this->checkoutService->removeProductEntries(session('cart', []), $product);
        session(['cart' => $cart]);

        return back()->with('status', 'Товар прибрано з кошика.');
    }

    public function checkout(): View|RedirectResponse
    {
        $payload = $this->checkoutService->cartPayload(session('cart', []));
        session(['cart' => $payload['cart']]);

        if ($payload['items']->isEmpty()) {
            return redirect()->route('cart')->with('status', 'Додайте товари перед оформленням.');
        }

        if (! $payload['can_checkout']) {
            return redirect()->route('cart')->with('status', $payload['messages'][0] ?? 'Перевірте кошик перед оформленням.');
        }

        $payload['paymentMethods'] = $this->checkoutService->activePaymentMethods();
        $payload['deliveryMethods'] = $this->checkoutService->activeDeliveryMethods();

        if ($payload['paymentMethods']->isEmpty() || $payload['deliveryMethods']->isEmpty()) {
            return redirect()->route('cart')->with('status', 'Оформлення тимчасово недоступне: немає активних способів оплати або доставки.');
        }

        $payload['checkout_token'] = $this->issueCheckoutToken();

        return view('storefront.checkout', $payload);
    }

    public function placeOrder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'checkout_token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'delivery_method' => ['required', 'string', 'max:255'],
            'payment_method' => ['required', 'string', 'max:255'],
            'customer_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $checkoutToken = (string) $validated['checkout_token'];

        if (! $this->reserveCheckoutToken($request, $checkoutToken)) {
            return redirect()->route('cart')->with('status', 'Сесія оформлення вже використана. Відкрийте checkout ще раз.');
        }

        try {
            $payload = $this->checkoutService->cartPayload(session('cart', []));
            session(['cart' => $payload['cart']]);

            if ($payload['items']->isEmpty()) {
                return redirect()->route('cart')->with('status', 'Кошик порожній.');
            }

            if (! $payload['can_checkout']) {
                return redirect()->route('cart')->with('status', $payload['messages'][0] ?? 'Перевірте кошик перед оформленням.');
            }

            $order = $this->checkoutService->placeOrder(session('cart', []), $validated);
        } catch (RuntimeException $exception) {
            $this->releaseCheckoutToken($request);

            return redirect()->route('cart')->with('status', $exception->getMessage());
        }

        session()->forget('cart');
        $this->clearCheckoutToken($request);

        return redirect()->route('checkout.thank-you', $order)->with('status', 'Замовлення прийнято.');
    }

    public function thankYou(Order $order): View
    {
        return view('storefront.thank-you', ['order' => $order->load(['items', 'currency', 'paymentMethod', 'deliveryMethod'])]);
    }

    public function switchCurrency(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'currency_id' => ['required', 'integer'],
            'redirect_to' => ['nullable', 'string', 'max:2000'],
        ]);

        $currency = $this->pricingService->selectCurrency((int) $validated['currency_id'], $request);
        $target = $this->safeRedirectTarget($validated['redirect_to'] ?? null);

        return redirect($target)->with('status', 'Валюту змінено на '.$currency->code.'.');
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

    private function issueCheckoutToken(): string
    {
        $token = Str::uuid()->toString();

        request()->session()->put(self::CHECKOUT_TOKEN_SESSION_KEY, $token);
        request()->session()->forget(self::CHECKOUT_PROCESSING_TOKEN_SESSION_KEY);

        return $token;
    }

    private function reserveCheckoutToken(Request $request, string $token): bool
    {
        $sessionToken = $request->session()->get(self::CHECKOUT_TOKEN_SESSION_KEY);

        if (! is_string($sessionToken) || ! hash_equals($sessionToken, $token)) {
            return false;
        }

        $processingToken = $request->session()->get(self::CHECKOUT_PROCESSING_TOKEN_SESSION_KEY);

        if (is_string($processingToken) && hash_equals($processingToken, $token)) {
            return false;
        }

        $request->session()->put(self::CHECKOUT_PROCESSING_TOKEN_SESSION_KEY, $token);

        return true;
    }

    private function releaseCheckoutToken(Request $request): void
    {
        $request->session()->forget(self::CHECKOUT_PROCESSING_TOKEN_SESSION_KEY);
    }

    private function clearCheckoutToken(Request $request): void
    {
        $request->session()->forget([
            self::CHECKOUT_TOKEN_SESSION_KEY,
            self::CHECKOUT_PROCESSING_TOKEN_SESSION_KEY,
        ]);
    }

    private function catalogProductQuery(Request $request, bool $allowCategoryFilter = true): Builder
    {
        $search = trim((string) $request->input('q', ''));

        $query = Product::active()
            ->with($this->checkoutService->productRelations())
            ->when($search !== '', fn (Builder $query) => PortableTextSearch::apply(
                $query,
                ['name', 'sku', 'short_description'],
                $search,
            ))
            ->when($allowCategoryFilter && $request->filled('category'), fn (Builder $query) => $query->whereRelation('category', 'slug', (string) $request->input('category')))
            ->when($request->filled('brand'), fn (Builder $query) => $query->whereRelation('brand', 'slug', (string) $request->input('brand')))
            ->when($request->boolean('sale'), fn (Builder $query) => $query->where('is_sale', true))
            ->when($request->boolean('new'), fn (Builder $query) => $query->where('is_new', true))
            ->when($request->boolean('hit'), fn (Builder $query) => $query->where('is_hit', true));

        $this->applyStockFilter($query, $request);

        match ($request->input('sort', 'new')) {
            'cheap' => $query->orderBy('price')->orderByDesc('created_at')->orderByDesc('id'),
            'expensive' => $query->orderByDesc('price')->orderByDesc('created_at')->orderByDesc('id'),
            'popular' => $query->orderByDesc('is_hit')->orderByDesc('is_sale')->orderByDesc('created_at')->orderByDesc('id'),
            default => $query->latest()->orderByDesc('id'),
        };

        return $query;
    }

    private function applyStockFilter(Builder $query, Request $request): void
    {
        $settings = CommerceSetting::current();

        match ($request->input('stock')) {
            'available' => $query
                ->whereIn('stock_status', ['in_stock', 'low_stock', 'preorder'])
                ->whereHas('stockBalances', fn (Builder $query) => $this->availableStockBalanceConstraint($query, $settings)),
            'preorder' => $query->where('stock_status', 'preorder'),
            'out' => $query->where(fn (Builder $query) => $query
                ->where('stock_status', 'out_of_stock')
                ->orWhereDoesntHave('stockBalances', fn (Builder $query) => $this->availableStockBalanceConstraint($query, $settings))),
            default => null,
        };
    }

    private function availableStockBalanceConstraint(Builder $query, CommerceSetting $settings): Builder
    {
        return $query
            ->when(! $settings->multi_warehouse_enabled, fn (Builder $query) => $query->where('warehouse_id', $settings->default_warehouse_id))
            ->whereRaw('(quantity - reserved_quantity) > 0')
            ->whereRelation('warehouse', 'is_active', true);
    }

    private function safeRedirectTarget(?string $target): string
    {
        if (! $target || ! str_starts_with($target, url('/'))) {
            return route('home');
        }

        return $target;
    }
}
