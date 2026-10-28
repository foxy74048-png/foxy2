<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_health_check_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}
