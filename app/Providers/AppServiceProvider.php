<?php

namespace App\Providers;

use App\Models\Category;
use App\Services\Commerce\ProductPricingService;
use App\Services\Themes\ThemeResolver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer(['layouts.storefront', 'storefront.*'], function ($view): void {
            $request = request();
            $storefrontPayload = $request->attributes->get('storefront_payload');

            if ($storefrontPayload === null) {
                $themeResolver = app(ThemeResolver::class);
                $theme = $themeResolver->resolveForRequest($request);
                $pricingService = app(ProductPricingService::class);

                $storefrontPayload = [
                    'navigationCategories' => Category::active()
                        ->orderBy('sort_order')
                        ->orderBy('name')
                        ->take(8)
                        ->get(),
                    'storefrontActiveCurrencies' => $pricingService->activeCurrencies(),
                    'storefrontCurrentCurrency' => $pricingService->currentCurrency($request),
                    'storefrontCurrencySwitcherVisible' => $pricingService->currencySwitcherVisible(),
                    'storefrontTheme' => $theme,
                    'themeCssVariables' => $themeResolver->getCssVariables($theme),
                    'themeLayoutConfig' => $themeResolver->getLayoutConfig($theme),
                    'themeComponentConfig' => $themeResolver->getComponentConfig($theme),
                    'themeStyleProfile' => $themeResolver->getStyleProfile($theme),
                    'isThemePreview' => $themeResolver->isPreview($theme, $request),
                ];

                $request->attributes->set('storefront_payload', $storefrontPayload);
            }

            $view->with($storefrontPayload);
        });
    }
}
