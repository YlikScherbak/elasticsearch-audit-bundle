<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Exception;

/**
 * A mistake in how auditing was declared: a field named as always recorded that is
 * not audited, an association without a representer, a collection tracking elements
 * it cannot reach.
 *
 * Its message is built from class, field and reason — never from a value — so it is
 * safe to repeat wherever the failure is reported, which is what makes a common
 * misconfiguration readable instead of arriving as a class name.
 *
 * Extends InvalidArgumentException, so code catching that (or LogicException) keeps
 * working.
 */
final class DeclarationMistake extends \InvalidArgumentException implements SafeExceptionMessage
{
}
