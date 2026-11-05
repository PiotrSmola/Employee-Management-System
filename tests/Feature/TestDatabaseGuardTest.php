<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TestDatabaseGuardTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $previousEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $name => $value) {
            $this->setEnv($name, $value === false ? null : $value);
        }

        parent::tearDown();
    }

    public static function refusedDatabases(): array
    {
        return [
            'production' => ['employees_management'],
            'production upper case' => ['EMPLOYEES_MANAGEMENT'],
            'workspace dev database' => ['ems_review'],
            'arbitrary name' => ['employees'],
            'test in the middle' => ['test_employees'],
            'suffix without separator' => ['employees_management_latest'],
            'empty' => [''],
        ];
    }

    #[DataProvider('refusedDatabases')]
    public function test_application_refuses_to_boot_against_a_non_test_database(string $database): void
    {
        $this->setEnvFor('DB_DATABASE', $database);

        try {
            // Only boots the application (no database connection is opened).
            $this->createApplication();
            $this->fail("The test application booted against the \"$database\" database.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to run tests against the "'.$database.'" database', $e->getMessage());
        }
    }

    public static function allowedDatabases(): array
    {
        return [['testing'], ['test'], ['ems_review_test'], ['employees_testing'], ['EMS_CI_TEST']];
    }

    #[DataProvider('allowedDatabases')]
    public function test_application_boots_against_a_dedicated_test_database(string $database): void
    {
        $this->setEnvFor('DB_DATABASE', $database);

        $app = $this->createApplication();

        $this->assertSame($database, $app['config']->get('database.connections.mysql.database'));
    }

    public function test_application_refuses_to_boot_when_a_connection_url_is_set(): void
    {
        $this->setEnvFor('DB_DATABASE', 'testing');
        $this->setEnvFor('DB_URL', 'mysql://root:root@db:3306/employees_management');

        try {
            $this->createApplication();
            $this->fail('The test application booted with a DB_URL that bypasses the database name check.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('DB_URL', $e->getMessage());
        }
    }

    private function setEnvFor(string $name, string $value): void
    {
        if (! array_key_exists($name, $this->previousEnv)) {
            $this->previousEnv[$name] = getenv($name);
        }

        $this->setEnv($name, $value);
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}
