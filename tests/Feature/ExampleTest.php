<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_renders_without_a_catalog(): void
    {
        $this->withoutVite();
        $this->get('/')->assertOk()->assertSee('Lieferportal');
        $this->getJson('/api/v1/restaurants')->assertExactJson(['data' => []]);
    }
}
