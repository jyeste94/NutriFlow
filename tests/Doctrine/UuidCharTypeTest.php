<?php

namespace App\Tests\Doctrine;

use App\Doctrine\Type\UuidCharType;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class UuidCharTypeTest extends TestCase
{
    public function testConvertToDatabaseValueUsesRfc4122StringForMysql(): void
    {
        $type = new UuidCharType();
        $platform = new MySQL80Platform();
        $uuid = Uuid::fromString('967ea8f8-427f-11f1-a9aa-6d4a2e089bf3');

        self::assertSame(
            '967ea8f8-427f-11f1-a9aa-6d4a2e089bf3',
            $type->convertToDatabaseValue($uuid, $platform)
        );
        self::assertSame(
            '967ea8f8-427f-11f1-a9aa-6d4a2e089bf3',
            $type->convertToDatabaseValue(" 967ea8f8-427f-11f1-a9aa-6d4a2e089bf3 \n", $platform)
        );
    }
}
