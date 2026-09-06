<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_around_formats_whole_inr_amounts(): void
    {
        $this->assertSame('Around ₹1,499', Money::around('1499.00', 'INR'));
    }

    public function test_format_returns_null_for_missing_price(): void
    {
        $this->assertNull(Money::around(null, 'INR'));
        $this->assertNull(Money::format('', 'INR'));
        $this->assertNull(Money::around(0, 'INR'));
        $this->assertNull(Money::around('0.00', 'INR'));
        $this->assertNull(Money::format(-10, 'INR'));
    }
}
