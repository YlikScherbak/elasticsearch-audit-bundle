<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Exception\WriteFailedException;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\AuditsABinary;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\AuditsABlob;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\KeepsABinaryUnaudited;
use Borsche\ElasticsearchAuditBundle\Tests\Fixtures\KeepsABlobUnaudited;
use Borsche\ElasticsearchAuditBundle\Writer\FailurePolicy;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A BLOB or BINARY column under #[AuditField].
 *
 * A BLOB's value is bytes, and a stream once Doctrine reads the row back: no history can hold
 * that, and json_encode cannot write it. The record used to be built anyway and was lost on
 * encoding, after the commit. The declaration is refused instead, like every other one that
 * cannot be honoured - on every flush it is met, through the failure policy - and the message
 * says what to audit in its place.
 *
 * A BINARY column is not refused: what the application wrote to it as a string is written as
 * it was. A stream written to one is refused by its value, naming what to record instead,
 * rather than lost on encoding.
 */
final class AnAuditedBlobTest extends DoctrineTestCase
{
    public function testTheDeclarationIsRefusedAndSaysWhatToAuditInstead(): void
    {
        $this->attachListener(FailurePolicy::Throw);
        $this->em->persist(new AuditsABlob('report.pdf', 'PDF-BYTES'));

        try {
            $this->em->flush();
            self::fail('the declaration should have been refused');
        } catch (WriteFailedException $refused) {
            $said = self::chain($refused);

            self::assertStringContainsString(AuditsABlob::class.'::$content is audited, but it is a binary column', $said);
            self::assertStringContainsString('its size, a checksum or the identifier of the file', $said);
            self::assertStringContainsString('AuditWriter::record()', $said, 'the message says how, not only what');
        }

        // Refused inside the flush, so nothing of it was committed.
        $this->em->clear();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$this->em->getClassMetadata(AuditsABlob::class)->getTableName()));
        self::assertSame([], $this->gateway->documents);
    }

    public function testEveryFlushMeetsTheRefusalAgain(): void
    {
        // Said each time rather than once and then forgotten: a declaration that cannot be
        // honoured is as wrong in the second flush as in the first.
        $this->attachListener(FailurePolicy::Log);

        $document = new AuditsABlob('report.pdf', 'PDF-BYTES');
        $this->em->persist($document);
        $this->em->flush();

        $document->name = 'report-v2.pdf';
        $this->em->flush();

        self::assertCount(2, array_filter($this->logs, static fn (string $line): bool => str_contains($line, '::$content is audited, but it is a binary column')));
    }

    public function testAStringInABinaryColumnIsWrittenAsItWas(): void
    {
        $this->attachListener(FailurePolicy::Log);

        $this->em->persist(new AuditsABinary('report.pdf', 'DIGEST'));
        $this->em->flush();

        self::assertSame(['old' => null, 'new' => 'DIGEST'], $this->documents()[0]['changes']['digest']);
        self::assertSame([], array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'binary column')), 'a BINARY declaration was refused');
    }

    public function testAStreamWrittenToAnAuditedColumnIsRefusedByNameNotLost(): void
    {
        // Until 1.2.6 the record carried the resource to the transport, whose encoder refused
        // it after the commit with nothing said of why. Now it is refused on the way out, by
        // its value, through the policy - and the message says what to record instead.
        $this->attachListener(FailurePolicy::Log);

        $digest = new AuditsABinary('report.pdf', 'DIGEST');
        $this->em->persist($digest);
        $this->em->flush();

        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, 'OTHER');
        rewind($stream);
        $digest->digest = $stream;
        $digest->name = 'with-stream.pdf';
        $this->em->flush();

        self::assertCount(1, $this->documents(), 'a record holding a stream reached the index');
        self::assertNotSame([], array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'is a resource') && str_contains($line, 'its size, a checksum')));
    }

    /** @return iterable<string, array{class-string, int}> */
    public static function unauditedBinaryColumns(): iterable
    {
        // Two types, two of DBAL's conversions; on PostgreSQL both are bytea, read back as a
        // stream. A BINARY column holds what its length allows.
        yield 'a BLOB' => [KeepsABlobUnaudited::class, 2000];
        yield 'a BINARY' => [KeepsABinaryUnaudited::class, 30];
    }

    /**
     * @param class-string<KeepsABlobUnaudited|KeepsABinaryUnaudited> $class
     */
    #[DataProvider('unauditedBinaryColumns')]
    public function testAnUnauditedBlobGoesToTheDatabaseWholeAndLeavesTheStreamWhereItWas(string $class, int $repeat): void
    {
        $this->attachListener(FailurePolicy::Throw);

        $bytes = str_repeat("BYTES\x00", $repeat);
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $bytes);
        rewind($stream);

        $attachment = new $class('a.pdf', $stream);
        $this->em->persist($attachment);
        $this->em->flush();

        self::assertSame(\strlen($bytes), ftell($stream), 'the stream was left somewhere the driver did not leave it');

        $attachment->name = 'b.pdf';
        $this->em->flush();

        $stored = $this->em->getConnection()->fetchOne('SELECT content FROM '.$this->em->getClassMetadata($class)->getTableName());
        self::assertSame($bytes, \is_resource($stored) ? stream_get_contents($stored) : $stored);

        self::assertSame(
            [['name' => ['old' => null, 'new' => 'a.pdf']], ['name' => ['old' => 'a.pdf', 'new' => 'b.pdf']]],
            array_map(static fn (array $document): array => $document['changes'], $this->documents()),
        );
    }

    private static function chain(\Throwable $e): string
    {
        $said = [];

        for ($link = $e; $link !== null; $link = $link->getPrevious()) {
            $said[] = $link->getMessage();
        }

        return implode("\n", $said);
    }
}
