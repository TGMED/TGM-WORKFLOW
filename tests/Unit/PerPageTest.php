<?php

namespace Tests\Unit;

use App\Support\PerPage;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class PerPageTest extends TestCase
{
    public function test_it_uses_a_size_on_offer(): void
    {
        $this->assertSame(50, PerPage::from(Request::create('/', 'GET', ['per_page' => '50']), 20));
    }

    public function test_it_falls_back_to_the_list_default(): void
    {
        $this->assertSame(20, PerPage::from(Request::create('/'), 20));
        $this->assertSame(20, PerPage::from(Request::create('/', 'GET', ['per_page' => '5000']), 20));
        $this->assertSame(20, PerPage::from(Request::create('/', 'GET', ['per_page' => 'all']), 20));
    }
}
