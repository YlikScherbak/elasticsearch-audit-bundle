<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class ElasticsearchAuditBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
