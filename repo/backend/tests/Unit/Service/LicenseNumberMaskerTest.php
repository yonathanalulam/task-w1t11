<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\LicenseNumberMasker;
use PHPUnit\Framework\TestCase;

final class LicenseNumberMaskerTest extends TestCase
{
    public function testMaskKeepsOnlyTrailingDigitsVisible(): void
    {
        $masker = new LicenseNumberMasker();

        self::assertSame('••••7122', $masker->mask('BAR-7122'));
        self::assertSame('••••1234', $masker->mask('1234'));
    }
}
