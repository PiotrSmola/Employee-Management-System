<?php

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsDirectory;
use Tests\TestCase;

class EmployeeApiTest extends TestCase
{
    use BuildsDirectory;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDirectory();
    }

    private function token(User $user, ?\DateTimeInterface $expiresAt = null): string
    {
        return $user->createToken('test', ['read'], $expiresAt)->plainTextToken;
    }

    /**
     * GET with a bearer token (the auth guard is reset so every call authenticates anew).
     */
    private function api(string $uri, ?string $token, array $query = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $request = $token === null ? $this : $this->withToken($token);

        return $request->getJson($uri.($query === [] ? '' : '?'.http_build_query($query)));
    }

    private function ids(TestResponse $response): array
    {
        return array_column($response->json('data'), 'emp_no');
    }

    // ---------------------------------------------------------------- auth

    public function test_requests_without_a_valid_token_get_a_json_401(): void
    {
        foreach ([null, 'nonsense', '1|'.str_repeat('a', 40)] as $token) {
            $this->api('/api/v1/employees', $token)
                ->assertStatus(401)
                ->assertExactJson(['error' => [
                    'status' => 401,
                    'code' => 'unauthenticated',
                    'message' => 'Unauthenticated. Send a valid API token: "Authorization: Bearer <token>".',
                ]]);
        }
    }

    public function test_a_web_session_does_not_authenticate_the_api(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/employees')->assertStatus(401);
    }

    public function test_errors_are_json_even_without_an_accept_header(): void
    {
        $this->get('/api/v1/employees')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_a_valid_token_authenticates_and_tracks_last_use(): void
    {
        $user = User::factory()->hr()->create();
        $token = $this->token($user);

        $this->api('/api/v1/employees', $token)->assertOk();

        $this->assertNotNull($user->tokens()->first()->last_used_at);
    }

    public function test_tokens_of_deactivated_users_are_refused(): void
    {
        $user = User::factory()->admin()->create();
        $token = $this->token($user);
        $user->update(['is_active' => false]);

        $this->api('/api/v1/employees', $token)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.message', 'Your account has been deactivated.');
    }

    public function test_revoked_and_expired_tokens_are_refused(): void
    {
        $user = User::factory()->admin()->create();
        $revoked = $this->token($user);
        $user->tokens()->delete();
        $expired = $this->token($user, now()->subMinute());

        $this->api('/api/v1/employees', $revoked)->assertStatus(401);
        $this->api('/api/v1/employees', $expired)->assertStatus(401);
    }

    public function test_unknown_endpoints_and_methods_are_json_errors(): void
    {
        $token = $this->token(User::factory()->admin()->create());

        $this->api('/api/v1/nope', $token)->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/employees')->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    }

    // ------------------------------------------------------------- listing

    public function test_the_list_with_pagination_meta_and_links(): void
    {
        $token = $this->token(User::factory()->admin()->create());

        $response = $this->api('/api/v1/employees', $token, ['per_page' => 3, 'page' => 2])->assertOk();

        $this->assertSame([4, 5, 6], $this->ids($response));
        $response->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 3)
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.from', 4)
            ->assertJsonPath('meta.to', 6);
        $this->assertStringContainsString('page=1', $response->json('links.prev'));
        $this->assertStringContainsString('page=3', $response->json('links.next'));
        $this->assertStringContainsString('per_page=3', $response->json('links.next'));
        $this->assertStringContainsString('page=3', $response->json('links.last'));

        $last = $this->api('/api/v1/employees', $token, ['per_page' => 3, 'page' => 3]);
        $this->assertSame([7], $this->ids($last));
        $this->assertNull($last->json('links.next'));

        $beyond = $this->api('/api/v1/employees', $token, ['per_page' => 3, 'page' => 9])->assertOk();
        $this->assertSame([], $beyond->json('data'));
    }

    public function test_list_items(): void
    {
        $token = $this->token(User::factory()->admin()->create());

        $data = collect($this->api('/api/v1/employees', $token)->json('data'))->keyBy('emp_no');

        $this->assertSame([
            'emp_no' => 1, 'first_name' => 'Anna', 'last_name' => 'Smith', 'gender' => 'F', 'hire_date' => '1990-03-01',
            'status' => 'current', 'department' => 'Alpha', 'title' => 'Engineer', 'salary' => 50000,
            'url' => route('api.v1.employees.show', 1),
        ], $data[1]);
        $this->assertSame(['former', 'Mid', 'Staff', null], [$data[3]['status'], $data[3]['department'], $data[3]['title'], $data[3]['salary']]);
        $this->assertSame([null, null], [$data[5]['department'], $data[5]['title']]);
    }

    public function test_the_list_uses_the_directory_filters_and_sorting(): void
    {
        $token = $this->token(User::factory()->admin()->create());

        $this->assertSame([1, 4], $this->ids($this->api('/api/v1/employees', $token, ['department' => $this->alpha->dept_no, 'gender' => 'F'])));
        $this->assertSame([1, 6], $this->ids($this->api('/api/v1/employees', $token, ['search' => 'Smith', 'title' => 'Engineer'])));
        $this->assertSame([3, 4, 5], $this->ids($this->api('/api/v1/employees', $token, ['status' => 'former'])));
        $this->assertSame([2, 7], $this->ids($this->api('/api/v1/employees', $token, ['salary_min' => 60000])));
        $this->assertSame([7], $this->ids($this->api('/api/v1/employees', $token, ['search' => '7'])), 'employee number search');
        $this->assertSame([2, 7, 6, 1, 5, 4, 3], $this->ids($this->api('/api/v1/employees', $token, ['sort' => 'salary', 'direction' => 'desc'])));

        $response = $this->api('/api/v1/employees', $token, ['gender' => 'M', 'sort' => 'name']);
        $response->assertJsonPath('meta.filters.gender', 'M')->assertJsonPath('meta.filters.sort', 'name');
    }

    public function test_invalid_parameters_are_a_422_with_fields(): void
    {
        $token = $this->token(User::factory()->admin()->create());

        $this->api('/api/v1/employees', $token, ['per_page' => 500, 'sort' => 'nope', 'status' => 'x', 'page' => 0])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['status', 'code', 'message', 'fields' => ['per_page', 'sort', 'status', 'page']]]);
    }

    // ----------------------------------------------------- scope & salaries

    public function test_a_managers_token_only_sees_their_department(): void
    {
        $token = $this->token(User::factory()->manager($this->alpha)->create());

        $this->assertSame([1, 4, 6], $this->ids($this->api('/api/v1/employees', $token)));
        $this->assertSame([1, 4, 6], $this->ids($this->api('/api/v1/employees', $token, ['department' => $this->omega->dept_no])), 'the department parameter cannot widen the scope');
        $this->api('/api/v1/employees', $token)->assertJsonPath('meta.filters.department', $this->alpha->dept_no);

        $this->api('/api/v1/employees/1', $token)->assertOk();
        $this->api('/api/v1/employees/2', $token)->assertStatus(403)->assertJsonPath('error.code', 'forbidden');

        $departments = $this->api('/api/v1/departments', $token)->json('data');
        $this->assertSame([$this->alpha->dept_no], array_column($departments, 'dept_no'));
    }

    public function test_viewers_get_no_salary_data(): void
    {
        $token = $this->token(User::factory()->viewer()->create());

        $list = $this->api('/api/v1/employees', $token)->assertOk();
        foreach ($list->json('data') as $item) {
            $this->assertArrayNotHasKey('salary', $item);
        }
        $this->assertStringNotContainsString('50000', $list->getContent());

        $detail = $this->api('/api/v1/employees/1', $token)->assertOk();
        $this->assertArrayNotHasKey('salary', $detail->json('data'));
        $this->assertArrayNotHasKey('salaries', $detail->json('data.history'));
        $this->assertStringNotContainsString('50000', $detail->getContent());

        $this->api('/api/v1/employees', $token, ['salary_min' => 1, 'sort' => 'salary'])
            ->assertStatus(422)
            ->assertJsonPath('error.fields.salary_min.0', 'Salary data is not available for your role.')
            ->assertJsonPath('error.fields.sort.0', 'Salary data is not available for your role.');
    }

    // --------------------------------------------------------------- detail

    public function test_employee_detail_with_histories(): void
    {
        $token = $this->token(User::factory()->hr()->create());

        $data = $this->api('/api/v1/employees/7', $token)->assertOk()->json('data');

        $this->assertSame(7, $data['emp_no']);
        $this->assertSame('Hank', $data['first_name']);
        $this->assertSame('current', $data['status']);
        $this->assertSame(['dept_no' => $this->mid->dept_no, 'name' => 'Mid'], $data['department']);
        $this->assertSame('Senior Staff', $data['title']);
        $this->assertSame(70000, $data['salary']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['birth_date']);

        $this->assertSame([$this->omega->dept_no, $this->mid->dept_no], array_column($data['history']['departments'], 'dept_no'));
        $this->assertNull($data['history']['departments'][1]['to'], 'current period: to = null');
        $this->assertNotNull($data['history']['departments'][0]['to']);
        $this->assertSame(['Staff', 'Senior Staff'], array_column($data['history']['titles'], 'title'));
        $this->assertSame(70000, last($data['history']['salaries'])['salary']);
        $this->assertNull(last($data['history']['salaries'])['to']);
    }

    public function test_detail_of_a_former_employee_and_unknown_numbers(): void
    {
        $token = $this->token(User::factory()->admin()->create());

        $former = $this->api('/api/v1/employees/3', $token)->json('data');
        $this->assertSame(['former', null, null, null], [$former['status'], $former['department'], $former['title'], $former['salary']]);
        $this->assertSame('1995-05-05', last($former['history']['departments'])['to']);

        $this->api('/api/v1/employees/999', $token)
            ->assertStatus(404)
            ->assertExactJson(['error' => ['status' => 404, 'code' => 'not_found', 'message' => 'Employee not found.']]);
        $this->api('/api/v1/employees/99999999999999999999', $token)->assertStatus(404);
        $this->api('/api/v1/employees/abc', $token)->assertStatus(404);
    }

    // ---------------------------------------------------------- departments

    public function test_departments_with_current_head_count(): void
    {
        $token = $this->token(User::factory()->viewer()->create());

        $data = collect($this->api('/api/v1/departments', $token)->assertOk()->json('data'))->keyBy('dept_no');

        $this->assertCount(3, $data);
        $this->assertSame(['dept_no' => $this->alpha->dept_no, 'name' => 'Alpha', 'current_employees' => 2], $data[$this->alpha->dept_no]);
        $this->assertSame(1, $data[$this->mid->dept_no]['current_employees']);
        $this->assertSame(1, $data[$this->omega->dept_no]['current_employees']);
    }

    // ---------------------------------------------------------- rate limit

    public function test_requests_are_rate_limited_per_user(): void
    {
        config(['data_tools.api.rate_limit' => 3]);
        $user = User::factory()->admin()->create();
        $first = $this->token($user);
        $second = $this->token($user);
        RateLimiter::clear('user:'.$user->id);

        $this->api('/api/v1/departments', $first)->assertOk()->assertHeader('X-RateLimit-Limit', 3);
        $this->api('/api/v1/departments', $first)->assertOk()->assertHeader('X-RateLimit-Remaining', 1);
        $this->api('/api/v1/departments', $second)->assertOk();

        $this->api('/api/v1/departments', $second)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'too_many_requests');

        // Another user has their own limit.
        $this->api('/api/v1/departments', $this->token(User::factory()->hr()->create()))->assertOk();
    }

    public function test_the_default_limit_is_sixty_per_minute(): void
    {
        $this->assertSame(60, (int) config('data_tools.api.rate_limit'));
    }

    // ---------------------------------------------------------- large ids

    public function test_new_employees_appear_in_the_api(): void
    {
        Employee::factory()->current($this->alpha, 'Engineer', 1)->create(['emp_no' => 500000, 'first_name' => 'Newbie']);
        $token = $this->token(User::factory()->admin()->create());

        $this->api('/api/v1/employees', $token, ['search' => '500000'])->assertJsonPath('data.0.first_name', 'Newbie');
    }
}
