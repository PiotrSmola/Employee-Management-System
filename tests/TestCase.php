<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** Databases the test suite must never touch (RefreshDatabase wipes them). */
    private const PROTECTED_DATABASES = ['employees_management'];

    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if (in_array(strtolower($database), self::PROTECTED_DATABASES, true)) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against the "%s" database. Point DB_DATABASE at a dedicated test database.',
                $database
            ));
        }

        return $app;
    }
}
