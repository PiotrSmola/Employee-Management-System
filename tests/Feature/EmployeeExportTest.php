<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_export_of_all_filtered_employees_except_excluded(): void
    {
        $sales = Department::factory()->create(['dept_name' => 'Sales']);
        $other = Department::factory()->create(['dept_name' => 'Other']);
        $kept = Employee::factory()->current($sales, 'Staff', 50000)->create(['last_name' => 'Kept']);
        $excluded = Employee::factory()->current($sales)->create(['last_name' => 'Excluded']);
        $filteredOut = Employee::factory()->current($other)->create(['last_name' => 'Elsewhere']);

        $response = $this->post(route('employees.export'), [
            'format' => 'csv',
            'department' => $sales->dept_no,
            'selectAll' => '1',
            'excludedEmployees' => [$excluded->emp_no],
        ]);

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $content = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($content))));

        $this->assertCount(2, $lines); // header + one employee
        $this->assertStringContainsString('Employee ID', $lines[0]);
        $this->assertStringContainsString((string) $kept->emp_no, $lines[1]);
        $this->assertStringContainsString('Sales', $lines[1]);
        $this->assertStringContainsString('50000.00', $lines[1]);
        $this->assertStringNotContainsString($excluded->last_name, $content);
        $this->assertStringNotContainsString($filteredOut->last_name, $content);
    }

    public function test_csv_export_of_explicitly_selected_employees(): void
    {
        [$first, $second] = Employee::factory()->count(2)->current()->create();

        $content = $this->post(route('employees.export'), [
            'format' => 'csv',
            'selectAll' => '0',
            'selectedEmployees' => [$second->emp_no],
        ])->assertOk()->streamedContent();

        $this->assertStringContainsString($second->last_name, $content);
        $this->assertStringNotContainsString($first->emp_no.',', $content);
    }

    public function test_export_without_selection_redirects_back_with_error(): void
    {
        $this->from(route('employees.index'))
            ->post(route('employees.export'), ['format' => 'csv', 'selectAll' => '0'])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('error');
    }
}
