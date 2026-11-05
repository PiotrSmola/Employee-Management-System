<?php

namespace Tests\Feature\Auth;

use App\Models\Employee;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use ActsAsAdmin;
    use RefreshDatabase;

    public function test_failed_pdf_export_shows_a_generic_message_without_exception_details(): void
    {
        Employee::factory()->current()->create();
        Pdf::shouldReceive('loadView')->andThrow(new RuntimeException('SQLSTATE secret /var/www/html/path'));

        $this->from(route('employees.index'))
            ->post(route('employees.export'), ['format' => 'pdf', 'selectAll' => '1'])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('error', fn (string $message) => ! str_contains($message, 'secret')
                && str_contains($message, 'PDF export failed'));
    }
}
