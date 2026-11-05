<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Names of databases the suite may run against (RefreshDatabase wipes them):
     * "testing", or anything ending in "_test" / "_testing" (e.g. ems_<workspace>_test).
     */
    private const TEST_DATABASE_PATTERN = '/(^|_)test(ing)?$/';

    public function createApplication()
    {
        $app = parent::createApplication();

        // phpunit.xml asks for array cache/session, but a CACHE_DRIVER / SESSION_DRIVER already present in the
        // process environment (e.g. the docker image) wins over it. Cached dashboard figures would then leak
        // from one test (and one run) into the next, so pin the stores here.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');

        self::guardTestDatabase($app['config']->get('database.default'), $app['config']->get('database.connections'));

        return $app;
    }

    /**
     * Refuse to run unless the default connection clearly points at a dedicated test database.
     * A connection URL is refused as well: it would override the database name checked here.
     *
     * @param  array<string, array<string, mixed>>|null  $connections
     */
    private static function guardTestDatabase(?string $connection, ?array $connections): void
    {
        $config = $connections[$connection] ?? [];
        $database = (string) ($config['database'] ?? '');

        if ((string) ($config['url'] ?? '') !== '') {
            throw new RuntimeException(sprintf(
                'Refusing to run tests: the "%s" connection is configured with a URL (DB_URL). Unset it and point DB_DATABASE at a dedicated test database.',
                $connection
            ));
        }

        if (! preg_match(self::TEST_DATABASE_PATTERN, strtolower($database))) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against the "%s" database. Point DB_DATABASE at a dedicated test database ("testing" or a name ending in "_test").',
                $database
            ));
        }
    }
}
