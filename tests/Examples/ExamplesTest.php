<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Examples;

use Borsche\ElasticsearchAuditBundle\Actor\ChainActorResolver;
use Borsche\ElasticsearchAuditBundle\Coalescing\AuditFrame;
use Borsche\ElasticsearchAuditBundle\Coalescing\FrameBuffer;
use Borsche\ElasticsearchAuditBundle\Doctrine\AuditSubscriber;
use Borsche\ElasticsearchAuditBundle\Doctrine\Metadata\AuditMetadataFactory;
use Borsche\ElasticsearchAuditBundle\Examples\Writing\ApprovingAnOrder;
use Borsche\ElasticsearchAuditBundle\Examples\Writing\Customer;
use Borsche\ElasticsearchAuditBundle\Examples\Writing\Order;
use Borsche\ElasticsearchAuditBundle\Examples\Writing\OrderLine;
use Borsche\ElasticsearchAuditBundle\Examples\Writing\RecordingActions;
use Borsche\ElasticsearchAuditBundle\Examples\Writing\SameDayComparator;
use Borsche\ElasticsearchAuditBundle\Examples\Writing\Ticket;
use Borsche\ElasticsearchAuditBundle\Examples\Writing\TicketComment;
use Borsche\ElasticsearchAuditBundle\Tests\FrozenClock;
use Borsche\ElasticsearchAuditBundle\Tests\InMemoryGateway;
use Borsche\ElasticsearchAuditBundle\Transport\SyncTransport;
use Borsche\ElasticsearchAuditBundle\Writer\AuditWriter;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use Borsche\ElasticsearchAuditBundle\Writer\IndexResolver;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * The examples, run.
 *
 * Documentation that is only read goes stale quietly: a signature moves, the
 * snippet keeps compiling in nobody's head, and the first person to copy it finds
 * out. These are real classes under the same PHPStan level as the source, and the
 * ones that can be executed are executed here — against the in-memory gateway for
 * the writer, against a real EntityManager for the entities.
 *
 * A failure here is not a broken test. It means the bundle no longer works the way
 * it is documented to, and one of the two has to change.
 */
final class ExamplesTest extends TestCase
{
    private InMemoryGateway $gateway;
    private AuditWriter $writer;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->gateway = new InMemoryGateway();
        $transport = new SyncTransport($this->gateway);
        $this->writer = new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock());
    }

    public function testTheIndexListsEveryExampleAndNothingElse(): void
    {
        // The index is how anybody finds these, and a file nobody links to is a file
        // nobody reads. Cheaper to assert than to remember.
        $index = file_get_contents(__DIR__.'/../../examples/README.md');
        self::assertIsString($index);

        foreach ($this->exampleFiles() as $path) {
            $relative = str_replace('\\', '/', substr($path, strlen(realpath(__DIR__.'/../../examples') ?: '') + 1));

            self::assertStringContainsString($relative, $index, sprintf('examples/README.md does not mention %s.', $relative));
        }
    }

    public function testAnActionIsRecordedWithItsDiffAndItsAttributes(): void
    {
        $actions = new RecordingActions($this->writer);

        $actions->aCallWasPlaced(7, 95);
        $actions->aPriceWasCorrected(3, 1000, 1200, 'supplier invoice');
        $actions->aLoginWasRefused('nobody@example.com', 'wrong password');
        $actions->aFileWasSharedAndTheScreenShowsItNow(12, 'auditor@example.com');
        $actions->aDraftWasDiscarded(4);

        $documents = $this->gateway->documents['audit_log'];

        self::assertSame(
            ['call.placed', 'price.corrected', 'login.refused', 'shared', 'remove'],
            array_column($documents, 'event'),
        );
        self::assertSame(95, $documents[0]['durationSeconds'], 'an attribute is a top-level field');
        self::assertSame(['old' => 1000, 'new' => 1200], $documents[1]['changes']['priceCents']);
        self::assertSame('nobody@example.com', $documents[2]['source'], 'the actor is the one the caller named');
        self::assertSame('system', $documents[0]['source'], 'and otherwise the resolver answers');
    }

    public function testTheComparatorAnswersOnlyForItsOwnFieldAndDefersOtherwise(): void
    {
        $comparator = new SameDayComparator();

        $morning = new \DateTimeImmutable('2026-09-06 09:00:00');
        $evening = new \DateTimeImmutable('2026-09-06 21:30:00');
        $tomorrow = new \DateTimeImmutable('2026-09-07 09:00:00');

        self::assertTrue($comparator->equals('order', 'deliverOn', $morning, $evening));
        self::assertFalse($comparator->equals('order', 'deliverOn', $morning, $tomorrow));
        self::assertNull($comparator->equals('order', 'status', 'draft', 'approved'), 'no opinion, rather than an answer');
        self::assertNull($comparator->equals('order', 'deliverOn', 'not a date', $morning));
    }

    public function testTheAnnotatedEntityIsAuditedTheWayItSaysItIs(): void
    {
        $this->needsDoctrine();

        $customer = new Customer('Acme');
        $order = new Order();
        $order->customer = $customer;
        $order->add(new OrderLine('SKU-1', 2));

        $this->em->persist($customer);
        $this->em->persist($order);
        $this->em->flush();

        $created = $this->documents()[0];

        self::assertSame('order', $created['objectType']);
        self::assertSame('create', $created['event']);
        self::assertSame('Acme', $created['changes']['customer']['new'], 'the association is stored as its representer sees it');
        self::assertArrayNotHasKey('internalNote', $created['changes'], 'an undeclared field is never recorded');

        // An element's own field, recorded on the owner.
        $order->lines->first()->quantity = 5;
        $order->totalCents = 5000;
        $this->em->flush();

        $updated = $this->documents()[1];
        $line = $order->lines->first()->id;

        self::assertSame(['old' => 2, 'new' => 5], $updated['changes'][sprintf('lines.%d.quantity', $line)]);
        self::assertSame(['old' => 'draft', 'new' => 'draft'], $updated['changes']['status'], 'alwaysRecord keeps the context beside the change, in the shape every other field has');
    }

    public function testTheRuntimeDeclarationIsAuditedTheSameWay(): void
    {
        $this->needsDoctrine();

        $reporter = new Customer('Acme');
        $ticket = new Ticket('The printer is on fire');
        $ticket->reporter = $reporter;
        $ticket->comments->add($comment = new TicketComment('It really is'));
        $comment->ticket = $ticket;

        $this->em->persist($reporter);
        $this->em->persist($ticket);
        $this->em->flush();

        $created = $this->documents()[0];

        self::assertSame('ticket', $created['objectType']);
        self::assertSame('The printer is on fire', $created['changes']['subject']['new']);
        self::assertStringContainsString('Acme', (string) $created['changes']['reporter']['new'], 'the closure decides what is stored');

        // The same entity, confidential: the field list is built per instance.
        $secret = new Ticket('Redundancies in Q4');
        $secret->confidential = true;
        $this->em->persist($secret);
        $this->em->flush();

        self::assertArrayNotHasKey('subject', $this->documents()[1]['changes'], 'a field the instance did not declare is not recorded');
    }

    public function testTheFrameTurnsAnOperationsManySavesIntoOneRecord(): void
    {
        $this->needsDoctrine();

        $order = new Order();
        $order->add(new OrderLine('SKU-1', 3));
        $this->em->persist($order);
        $this->em->flush();

        $buffer = new FrameBuffer();
        $writer = $this->attachListener($buffer);
        $frame = new AuditFrame($buffer, $writer);

        $before = \count($this->documents());
        (new ApprovingAnOrder($frame, $this->em))->approve($order);

        $written = \array_slice($this->documents(), $before);

        self::assertCount(1, $written, 'two flushes, one operation, one record');
        self::assertSame(['old' => 'draft', 'new' => 'approved'], $written[0]['changes']['status']);
        self::assertSame(['old' => 0, 'new' => 3000], $written[0]['changes']['totalCents']);
    }

    public function testAValueThatCameBackToWhereItStartedIsNotHistory(): void
    {
        $this->needsDoctrine();

        $order = new Order();
        $order->totalCents = 4000;
        $this->em->persist($order);
        $this->em->flush();

        $buffer = new FrameBuffer();
        $writer = $this->attachListener($buffer);
        $frame = new AuditFrame($buffer, $writer);

        $before = \count($this->documents());
        (new ApprovingAnOrder($frame, $this->em))->reprice($order, 0);

        self::assertCount($before, $this->documents(), 'the reversal and the reapplication cancel out');
        self::assertSame(4000, $order->totalCents);
    }

    /**
     * Every example file, so the index guard cannot be fooled by a new directory.
     *
     * @return list<string>
     */
    private function exampleFiles(): array
    {
        $root = realpath(__DIR__.'/../../examples');
        self::assertIsString($root);

        // Everything, not only the PHP: the configuration example is a file somebody
        // has to be able to find too.
        /** @var iterable<\SplFileInfo> $found */
        $found = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        $files = [];

        foreach ($found as $file) {
            if ($file->isFile() && $file->getFilename() !== 'README.md') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function needsDoctrine(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed to run the entity examples.');
        }

        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__.'/../../examples']));
        $config->setProxyDir(sys_get_temp_dir().'/borsche-audit-proxies');
        $config->setProxyNamespace('BorscheAuditProxies');
        $config->setAutoGenerateProxyClasses(true);

        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }

        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->attachListener();
    }

    private function attachListener(?FrameBuffer $buffer = null): AuditWriter
    {
        $transport = new SyncTransport($this->gateway);
        $writer = new AuditWriter($transport, $transport, new IndexResolver('audit_log'), new ChainActorResolver([], 'system'), new FrozenClock(), [], FailurePolicy::Throw, null, null, $buffer);

        foreach (array_filter(
            $this->em->getEventManager()->getListeners(\Doctrine\ORM\Events::postFlush),
            static fn (object $listener): bool => $listener instanceof AuditSubscriber,
        ) as $previous) {
            $this->em->getEventManager()->removeEventListener(AuditSubscriber::EVENTS, $previous);
        }

        $this->em->getEventManager()->addEventListener(AuditSubscriber::EVENTS, new AuditSubscriber($writer, new AuditMetadataFactory()));

        return $writer;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documents(): array
    {
        return $this->gateway->documents['audit_log'] ?? [];
    }
}
