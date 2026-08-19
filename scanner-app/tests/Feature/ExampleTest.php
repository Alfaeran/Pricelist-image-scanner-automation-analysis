<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    // AutoAuthenticateDesktop resolves a local user on every request, so even
    // the root route needs the schema present.
    use RefreshDatabase;

    public function test_the_root_route_sends_you_straight_to_the_scanner(): void
    {
        // The desktop app has no landing page or login flow to stop at.
        $this->get('/')->assertRedirect('/scanner');
    }

    public function test_the_scanner_loads_without_signing_in(): void
    {
        $this->get('/scanner')->assertStatus(200);
    }

    public function test_a_local_user_is_created_and_signed_in_automatically(): void
    {
        $this->assertGuest();

        $this->get('/scanner')->assertStatus(200);

        $this->assertAuthenticated();
        $this->assertSame('local@desktop.app', auth()->user()->email);
    }
}
