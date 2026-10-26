<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Basic application smoke test.
 * The root URL redirects unauthenticated users to /login — this is the correct behaviour.
 */
class ExampleTest extends TestCase
{
    public function test_the_root_url_redirects_unauthenticated_users_to_login(): void
    {
        // Root '/' should redirect to the login page for unauthenticated users
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_login_page_is_accessible(): void
    {
        $this->get(route('login'))->assertOk();
    }
}
