<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Author;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Comment;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;

/**
 * A late record -- its flush's publishing swallowed, written by the next flush, on another
 * manager -- names a target of a class whose rows the bundle does not read by the object the
 * application holds when the representer runs: the one of the manager whose flush it was, as that
 * manager holds it then (README, the late road). The record's id, actor and moment are its own.
 */
final class WhatALateRecordShowsOfAnUnwatchedTargetTest extends DoctrineTestCase
{
    public function testItIsTheObjectOfTheManagerWhoseFlushItWas(): void
    {
        $this->em->persist($alice = new Author('Alice'));
        $this->em->persist($comment = new Comment('c-1', 'Hello'));
        $this->em->flush();
        $this->gateway->documents = [];

        $comment->author = $alice;
        $this->swallowed();
        $alice->name = 'Alice (unsaved)';

        $other = new EntityManager($this->em->getConnection(), $this->em->getConfiguration(), $this->em->getEventManager());
        $other->persist(new Author('Bob'));
        $other->flush();

        $late = array_values(array_filter($this->documents(), static fn (array $d): bool => $d['objectType'] === 'comment'));
        self::assertSame([['c-1', ['old' => null, 'new' => 'Alice (unsaved)']]], array_map(static fn (array $d): array => [$d['objectId'], $d['changes']['author'] ?? null], $late));
    }

    private function swallowed(): void
    {
        $manager = $this->em->getEventManager();
        $breaker = new class {
            public function postFlush(): void
            {
                throw new \DomainException('somebody else exploded in postFlush');
            }
        };
        $ours = $manager->getListeners(Events::postFlush);

        foreach ($ours as $listener) {
            $manager->removeEventListener([Events::postFlush], $listener);
        }

        $manager->addEventListener([Events::postFlush], $breaker);

        foreach ($ours as $listener) {
            $manager->addEventListener([Events::postFlush], $listener);
        }

        try {
            $this->em->flush();
        } catch (\DomainException) {
            // the application copes
        } finally {
            $manager->removeEventListener([Events::postFlush], $breaker);
        }
    }
}
