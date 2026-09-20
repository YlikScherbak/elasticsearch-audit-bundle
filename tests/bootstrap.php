<?php

declare(strict_types=1);

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\SkuType;
use Doctrine\DBAL\Types\Type;

require __DIR__.'/../vendor/autoload.php';

// One fixture is identified by an object, and a mapping that names a type Doctrine does
// not know about fails when the schema is built — not when that fixture is used. Every
// suite here builds a schema from the whole Fixtures directory, so the registration
// belongs to the process rather than to whichever test case happens to run first.
//
// Found the other way round: registering it in DoctrineTestCase worked for the whole
// suite and broke the matrix job, which runs tests/Outbox before tests/Doctrine.
if (!Type::hasType(SkuType::NAME)) {
    Type::addType(SkuType::NAME, SkuType::class);
}
