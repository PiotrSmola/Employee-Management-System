<?php

namespace Tests\Feature\Http;

use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guards for the extension points of docs/EXTENDING.md: feature route files
 * and navbar partials.
 */
class RouteAndNavigationTest extends TestCase
{
    use RefreshDatabase;

    /** Routes that may be reached without the authenticated group. */
    private const PUBLIC_ROUTES = ['login', 'login.store'];

    public function test_every_application_route_requires_an_active_signed_in_user(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->getActionName(), 'App\\'))
            ->reject(fn (RoutingRoute $route) => in_array($route->getName(), self::PUBLIC_ROUTES, true));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            foreach (['auth', 'auth.session', 'active'] as $required) {
                $this->assertContains($required, $middleware, "Route {$route->uri()} must be in the authenticated group.");
            }
        }
    }

    public function test_every_feature_route_file_is_loaded(): void
    {
        $this->assertTrue(Route::has('employees.show'));
        $this->assertTrue(Route::has('account.edit'));
        $this->assertTrue(Route::has('users.index'));
    }

    public function test_employee_routes_only_match_numeric_employee_numbers(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/employees/abc')->assertNotFound();
        $this->get('/employees/abc/export-pdf')->assertNotFound();
    }

    public function test_navbar_partials_are_listed_in_file_name_order(): void
    {
        $this->assertSame([
            'layouts.nav.main.10-dashboard',
            'layouts.nav.main.20-employees',
            'layouts.nav.main.90-users',
        ], array_values(array_filter(
            Navigation::partials(Navigation::MAIN),
            fn (string $view) => in_array($view, ['layouts.nav.main.10-dashboard', 'layouts.nav.main.20-employees', 'layouts.nav.main.90-users'], true),
        )));

        $user = Navigation::partials(Navigation::USER);
        $this->assertLessThan(array_search('layouts.nav.user.90-users', $user, true), array_search('layouts.nav.user.10-account', $user, true));
    }

    public function test_navbar_renders_the_items_in_order(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('index'))
            ->assertOk()
            ->assertSeeInOrder(['Dashboard', 'All Employees', 'Export Data', 'Users', 'My account', 'Users', 'Log out']);
    }
}
