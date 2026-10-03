<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Privacy;

final class WrapsAnArray implements \JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        return ['a' => 1];
    }
}
