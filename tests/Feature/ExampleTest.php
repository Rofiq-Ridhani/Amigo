<?php

// file: tests/Feature/ExampleTest.php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Halaman utama diarahkan ke /chat (yang lalu meminta login).
     */
    public function test_halaman_utama_diarahkan_ke_chat(): void
    {
        $this->get('/')->assertRedirect('/chat');
    }
}
