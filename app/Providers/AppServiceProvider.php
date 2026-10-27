<?php

namespace App\Providers;

use App\Http\Middleware\RequireAdminMfa;
use App\Http\Responses\AdminLogoutResponse;
use App\Mail\SyntheticTransport;
use App\Models\Catalog\Ingredient;
use App\Models\Catalog\IngredientCategory;
use App\Models\Catalog\KitchenEquipment;
use App\Models\Catalog\KitchenEquipmentCategory;
use App\Models\Catalog\RecipeCategory;
use App\Models\Catalog\Uom;
use App\Models\ClientMealPlanAssignment;
use App\Models\Food;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanVersion;
use App\Models\MealType;
use App\Policies\CatalogPolicy;
use App\Policies\MealPlanDetailPolicy;
use App\Policies\MealPlanPolicy;
use App\Services\Auth\Client\ClientAuth;
use App\Services\Auth\MfaService;
use App\Services\Auth\Restore\ExternalEpoch;
use App\Services\Auth\Restore\LocalComposeEpoch;
use App\Services\Auth\Restore\LocalDemoEpoch;
use App\Services\Auth\Restore\SyntheticEpoch;
use App\Services\Auth\Restore\SyntheticMaintenanceMode;
use App\Services\Catalog\R2ObjectLister;
use App\Services\Catalog\R2ObjectSource;
use App\Support\Session\ContextDatabaseSessionHandler;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(R2ObjectSource::class, R2ObjectLister::class);
        if ($this->app->environment('testing') && getenv('TELMANFIT_PG_TEST') === '1') {
            $this->app->singleton(ExternalEpoch::class, SyntheticEpoch::class);
            if (config('database.default') === 'pgsql') {
                $this->app->singleton(MaintenanceMode::class, SyntheticMaintenanceMode::class);
            }
        }
        if ($this->app->environment('local') && config('local_demo.enabled') === true) {
            $this->app->singleton(ExternalEpoch::class, LocalDemoEpoch::class);
        }
        if ($this->app->environment('local') && config('local_compose.enabled') === true) {
            $this->app->singleton(ExternalEpoch::class, LocalComposeEpoch::class);
        }
        $this->app->singleton(MfaService::class);
        $this->app->singleton(ClientAuth::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Ingredient::class, IngredientCategory::class,
            RecipeCategory::class, Uom::class,
            KitchenEquipment::class, KitchenEquipmentCategory::class] as $model) {
            Gate::policy($model, CatalogPolicy::class);
        }
        Gate::policy(MealPlan::class, MealPlanPolicy::class);
        foreach ([MealPlanVersion::class, MealPlanDay::class,
            Meal::class, MealType::class, MealItem::class,
            ClientMealPlanAssignment::class, Food::class] as $model) {
            Gate::policy($model, MealPlanDetailPolicy::class);
        }
        // Спільна таблиця: request lifetime не визначає TTL інших contexts.
        app('session')->extend('database', fn ($app) => new ContextDatabaseSessionHandler(
            $app['db']->connection(config('session.connection')), config('session.table'), config('session.lifetime'), $app
        ));
        Mail::extend('auth4_synthetic', fn () => new SyntheticTransport);
        if (config('mfa.enabled')) {
            $this->app->bind(LogoutResponse::class, AdminLogoutResponse::class);
            Livewire::setUpdateRoute(fn ($handle) => Route::post('/admin/livewire/update', $handle)
                ->middleware(['web', RequireAdminMfa::class]));
        }
    }
}
