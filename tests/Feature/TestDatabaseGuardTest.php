<?php

namespace Tests\Feature;

use RuntimeException;
use Tests\TestCase;

class TestDatabaseGuardTest extends TestCase
{
    public function test_application_refuses_to_boot_against_the_production_database(): void
    {
        $previous = getenv('DB_DATABASE');
        $this->setDatabaseEnv('employees_management');

        try {
            // Only boots the application (no database connection is opened).
            $this->createApplication();
            $this->fail('The test application booted against the production database.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('employees_management', $e->getMessage());
        } finally {
            $this->setDatabaseEnv($previous === false ? null : $previous);
        }
    }

    private function setDatabaseEnv(?string $value): void
    {
        if ($value === null) {
            putenv('DB_DATABASE');
            unset($_ENV['DB_DATABASE'], $_SERVER['DB_DATABASE']);

            return;
        }

        putenv('DB_DATABASE='.$value);
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $value;
    }
}
