<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionCookieTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_cookie_is_not_readable_by_scripts_or_sent_cross_site(): void
    {
        $cookie = $this->get(route('login'))->getCookie(config('session.cookie'), false);

        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_secure_flag_follows_the_configuration(): void
    {
        config(['session.secure' => true]);

        $this->assertTrue($this->get(route('login'))->getCookie(config('session.cookie'), false)->isSecure());
    }

    public function test_sessions_are_encrypted_by_default(): void
    {
        $this->assertTrue(config('session.encrypt'));
    }
}
