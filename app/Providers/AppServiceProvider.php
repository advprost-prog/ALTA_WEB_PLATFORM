<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use App\Policies\AddonArtifactPromotionPolicy;
use App\Policies\AddonArtifactStagingPolicy;
use App\Services\Commerce\ProductPricingService;
use App\Services\Themes\ThemeResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

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
        Gate::define('review-addon-artifacts', static function (?User $user): bool {
            if ($user === null) {
                return false;
            }

            return $user->role === UserRole::Admin;
        });

        Gate::define('stage-addon-artifacts', static fn (User $user): bool => AddonArtifactStagingPolicy::canManage($user));

        Gate::define('promote-addon-artifacts', static fn (User $user): bool => AddonArtifactPromotionPolicy::canPromote($user));
        Gate::define('rollback-addon-artifacts', static fn (User $user): bool => AddonArtifactPromotionPolicy::canRollback($user));

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
