<?php

namespace Draw\Bundle\SonataExtraBundle\Doctrine\DBALTypes;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\TimeImmutableType;

class UtcTimeImmutableType extends TimeImmutableType
{
    use UtcPhpDateTimeMappingTypeTrait;

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof DateTimeImmutable) {
            $value = clone $value;
            $value = $value->setTimezone(new DateTimeZone('UTC'));
        }

        return parent::convertToDatabaseValue($value, $platform);
    }
}
