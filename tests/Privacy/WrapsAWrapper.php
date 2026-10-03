<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Privacy;

final class WrapsAWrapper implements \JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        return new WrapsAnArray();
    }
}
