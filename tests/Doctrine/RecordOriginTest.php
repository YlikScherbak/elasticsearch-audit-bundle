<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Contract\AuditEnricherInterface;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Model\AuditRecord;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\Article;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Doctrine\ORM\Events;

/**
 * Telling a record the listener built from one the application wrote by hand — the
 * question an enricher used to answer by guessing from the actor.
 */
final class RecordOriginTest extends DoctrineTestCase
{
    public function testWhatTheListenerBuildsIsMarkedAsDoctrines(): void
    {
        $this->stampOrigin();

        $this->em->persist(new Article('First'));
        $this->em->flush();

        self::assertSame('doctrine', $this->lastDocument()['origin']);
    }

    public function testWhatTheApplicationWritesIsItsOwn(): void
    {
        $writer = $this->writer(FailurePolicy::Log, [self::enricher()]);

        $writer->record('order', 1, 'called', []);

        self::assertSame('manual', $this->lastDocument()['origin']);
    }

    private function stampOrigin(): void
    {
        $attached = array_values(array_filter(
            $this->em->getEventManager()->getListeners(Events::postFlush),
            static fn (object $l) => $l instanceof AuditSubscriber,
        ));

        $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, ...$attached);
        $this->attachListener(FailurePolicy::Log, null, [self::enricher()]);
    }

    private static function enricher(): AuditEnricherInterface
    {
        return new class implements AuditEnricherInterface {
            public function supports(AuditRecord $record): bool
            {
                return true;
            }

            public function enrich(AuditRecord $record): AuditRecord
            {
                return $record->withAttributes(['origin' => $record->origin->value]);
            }

            public function mapping(): array
            {
                return ['origin' => ['type' => 'keyword']];
            }
        };
    }
}
