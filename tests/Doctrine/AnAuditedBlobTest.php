<?php

declare(strict_types=1);

namespace Borsche\ElasticsearchAuditBundle\Tests\Doctrine;

use Borsche\ElasticsearchAuditBundle\Doctrine\Observation\ObservingMiddleware;
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
 * Its value is bytes, and a stream once Doctrine reads the row back: no history can hold that,
 * and json_encode cannot write it. The record used to be built anyway and was lost on encoding,
 * after the commit. The declaration is refused instead, like every other one that cannot be
 * honoured - on every flush it is met, through the failure policy - and the message says what
 * to audit in its place.
 *
 * Under on_failure: throw that refusal now comes from inside the flush, so the flush does not
 * commit; it used to commit and lose the record afterwards.
 */
final class AnAuditedBlobTest extends DoctrineTestCase
{
    public function testTheDeclarationIsRefusedAndSaysWhatToAuditInstead(): void
    {
        $class = AuditsABlob::class;
        $field = 'content';
        $this->attachListener(FailurePolicy::Throw);
        $this->em->persist(new AuditsABlob('report.pdf', 'PDF-BYTES'));

        try {
            $this->em->flush();
            self::fail('the declaration should have been refused');
        } catch (WriteFailedException $refused) {
            $said = self::chain($refused);

            self::assertStringContainsString($class.'::$'.$field.' is audited, but it is a binary column', $said);
            self::assertStringContainsString('its size, a checksum or the identifier of the file', $said);
            self::assertStringContainsString('AuditWriter::record()', $said, 'the message says how, not only what');
        }

        // Refused inside the flush, so nothing of it was committed.
        $this->em->clear();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$this->em->getClassMetadata($class)->getTableName()));
        self::assertSame([], $this->gateway->documents);
    }

    public function testABinaryColumnIsWrittenAsItWasAndAStreamInItIsRefusedByItsValue(): void
    {
        // Not a declaration to refuse: DBAL 4 reads a BINARY column back as a string, and a
        // string in it was written before 1.3.1 and is now. What cannot be written is a stream
        // - what DBAL 3 reads it back as, or what the application put there - and that is
        // refused where every stream is, by its value, naming what to record instead.
        $this->attachListener(FailurePolicy::Log);

        $digest = new AuditsABinary('report.pdf', 'DIGEST');
        $this->em->persist($digest);
        $this->em->flush();

        if (ObservingMiddleware::onDbal3()) {
            // DBAL 3 turns every BINARY value into a stream, the one just written too: such a
            // record was lost on encoding before 1.3.1, and is refused by its value now.
            self::assertSame([], $this->documents());
            self::assertNotEmpty(array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'is a resource')));
            self::assertSame([], array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'binary column')), 'a BINARY declaration was refused');

            return;
        }

        self::assertSame(['old' => null, 'new' => 'DIGEST'], $this->documents()[0]['changes']['digest']);

        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, 'OTHER');
        rewind($stream);
        $digest->digest = $stream;
        $this->em->flush();

        // Not a record saying it became '': the driver read the stream to its end, and what was
        // left of it is not what the row holds.
        self::assertCount(1, $this->documents(), 'a record holding a stream was written');
        self::assertNotEmpty(array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'is a resource')));
        self::assertSame([], array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'binary column')), 'a BINARY declaration was refused');
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

        $refusals = array_filter($this->logs, static fn (string $line): bool => str_contains($line, '::$content is audited, but it is a binary column'));

        self::assertCount(2, $refusals);

        // The refusal is the declaration's, not the entity's: what can be recorded is, as for
        // every other declaration that cannot be honoured. The insertion carried the bytes and
        // is not written; the rename carries none and is.
        self::assertSame(
            [['update', ['name' => ['old' => 'report.pdf', 'new' => 'report-v2.pdf']]]],
            array_map(static fn (array $d): array => [$d['event'], $d['changes']], $this->documents()),
        );
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
        // The statements carrying the bytes are watched like any other: the listener must not
        // read the stream, move it, or take what the driver writes.
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
