<?php

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Symfony\Bridge\Doctrine\Types\AbstractUidType;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\Uuid;

final class UuidCharType extends AbstractUidType
{
    public const NAME = 'uuid';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getGuidTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof AbstractUid) {
            return $value->toRfc4122();
        }

        if (null === $value || '' === $value) {
            return null;
        }

        if (!is_string($value)) {
            $this->throwInvalidTypeCompat($value);
        }

        try {
            return Uuid::fromString(trim($value))->toRfc4122();
        } catch (\InvalidArgumentException $e) {
            $this->throwValueNotConvertibleCompat($value, $e);
        }
    }

    protected function getUidClass(): string
    {
        return Uuid::class;
    }

    private function throwInvalidTypeCompat(mixed $value): never
    {
        throw \Doctrine\DBAL\Types\ConversionException::conversionFailedInvalidType(
            $value,
            $this->getName(),
            ['null', 'string', AbstractUid::class]
        );
    }

    private function throwValueNotConvertibleCompat(mixed $value, \Throwable $previous): never
    {
        throw \Doctrine\DBAL\Types\ConversionException::conversionFailed($value, $this->getName(), $previous);
    }
}
