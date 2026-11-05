<?php

namespace Tests\Feature\Support;

use App\Enums\Role;
use App\Livewire\EmployeeFilter;
use App\Models\Department;
use App\Models\Employee;
use App\Support\EmployeeDataCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class EmployeeDataCacheTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_flush_clears_every_key_the_dashboard_and_filters_cache(): void
    {
        $department = Department::factory()->create();
        Employee::factory()->current($department)->create();

        $this->get(route('index'))->assertOk();
        $this->signInAs(Role::Manager, $department);
        $this->get(route('index'))->assertOk();
        Livewire::test(EmployeeFilter::class);

        $keys = [
            EmployeeDataCache::DASHBOARD_STATS,
            EmployeeDataCache::EMPLOYEES_BY_DEPARTMENT,
            EmployeeDataCache::departmentStats($department->dept_no),
            EmployeeDataCache::departmentTitleBreakdown($department->dept_no),
            EmployeeDataCache::departmentJobTitles($department->dept_no),
        ];
        foreach ($keys as $key) {
            $this->assertTrue(Cache::has($key), "{$key} should be cached by the pages.");
        }

        EmployeeDataCache::flush();

        foreach ($keys as $key) {
            $this->assertFalse(Cache::has($key), "{$key} should be flushed.");
        }
    }

    public function test_flush_can_be_limited_to_some_departments(): void
    {
        [$a, $b] = Department::factory()->count(2)->create();
        foreach ([$a, $b] as $department) {
            Cache::put(EmployeeDataCache::departmentStats($department->dept_no), 1);
        }
        Cache::put(EmployeeDataCache::DASHBOARD_STATS, 1);

        EmployeeDataCache::flush([$a->dept_no]);

        $this->assertFalse(Cache::has(EmployeeDataCache::departmentStats($a->dept_no)));
        $this->assertTrue(Cache::has(EmployeeDataCache::departmentStats($b->dept_no)));
        $this->assertFalse(Cache::has(EmployeeDataCache::DASHBOARD_STATS));
    }

    public function test_a_manager_without_department_has_its_filter_cache_flushed_too(): void
    {
        Cache::put(EmployeeDataCache::departmentJobTitles('-'), 1);

        EmployeeDataCache::flush();

        $this->assertFalse(Cache::has(EmployeeDataCache::departmentJobTitles('-')));
    }
}
