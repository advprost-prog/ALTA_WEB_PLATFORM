<?php

use App\Http\Controllers\StorefrontController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', [StorefrontController::class, 'home'])->name('home');
Route::get('/catalog', [StorefrontController::class, 'catalog'])->name('catalog');
Route::get('/category/{category:slug}', [StorefrontController::class, 'category'])->name('category.show');
Route::get('/product/{product:slug}', [StorefrontController::class, 'product'])->name('product.show');
Route::post('/currency', [StorefrontController::class, 'switchCurrency'])->name('currency.switch');

Route::get('/cart', [StorefrontController::class, 'cart'])->name('cart');
Route::post('/cart/{product}/add', [StorefrontController::class, 'addToCart'])->name('cart.add');
Route::patch('/cart', [StorefrontController::class, 'updateCart'])->name('cart.update');
Route::delete('/cart/{product}', [StorefrontController::class, 'removeFromCart'])->name('cart.remove');

Route::get('/checkout', [StorefrontController::class, 'checkout'])->name('checkout');
Route::post('/checkout', [StorefrontController::class, 'placeOrder'])->name('checkout.place');
Route::get('/checkout/thank-you/{order}', [StorefrontController::class, 'thankYou'])->name('checkout.thank-you');

Route::get('/delivery-payment', [StorefrontController::class, 'deliveryPayment'])->name('delivery-payment');
Route::get('/contacts', [StorefrontController::class, 'contacts'])->name('contacts');
Route::get('/about', [StorefrontController::class, 'about'])->name('about');

Route::get('/_debug/storage-check', function (Request $request) {
    if (! app()->environment(['local', 'testing'])) {
        abort(404);
    }

    $path = trim((string) $request->query('path', ''));
    $normalizedPath = ltrim($path, '/');
    $publicStoragePath = public_path('storage/'.$normalizedPath);
    $storageExists = Storage::disk('public')->exists($normalizedPath);
    $storageUrl = Storage::disk('public')->url($normalizedPath);

    return response()->json([
        'app.url' => config('app.url'),
        'request.root' => $request->root(),
        'public.disk.url' => config('filesystems.disks.public.url'),
        'public.disk.root' => config('filesystems.disks.public.root'),
        'requested.path' => $normalizedPath,
        'storage.exists' => $storageExists,
        'storage.url' => $storageUrl,
        'asset.url' => asset('storage/'.$normalizedPath),
        'public.storage.exists' => file_exists($publicStoragePath),
    ], 200);
});
