<?php

namespace Tests\Feature;

use Tests\TestCase;

class LoginPageTest extends TestCase
{
    public function test_login_page_uses_the_branded_design(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('Welcome!');
        $response->assertSee('Inventory Management System');
        $response->assertSee('Sign in with your username and password to manage inventory, orders and deliveries.');
        $response->assertSee('/images/login-banner.jpg', false);
        $response->assertSee('Authorized personnel only.');
        $response->assertSee('togglePassword', false);
        $response->assertDontSee('Login to manage inventory, orders and deliveries');
    }

    public function test_login_page_keeps_the_form_wired_to_the_login_route(): void
    {
        $response = $this->get(route('login'));

        $response->assertSee('action="' . route('login') . '"', false);
        $response->assertSee('name="username"', false);
        $response->assertSee('name="password"', false);
    }
}
