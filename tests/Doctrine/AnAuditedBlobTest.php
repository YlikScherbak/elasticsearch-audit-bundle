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

    public function testABinaryColumnIsWrittenAsItWasAndAStreamInItIsDoubtNotARecordLost(): void
    {
        // Not a declaration to refuse: DBAL 4 reads a BINARY column back as a string, and a
        // string in it was written before 1.3.1 and is now. A stream in it - what DBAL 3 turns
        // every value of one into, or what the application put there - the driver reads to its
        // end, so what it held is nothing the history can read: that field is doubt, and the rest
        // of the record is written.
        $this->attachListener(FailurePolicy::Log);
        $this->unownedStatementsAreExpected = true;

        $digest = new AuditsABinary('report.pdf', 'DIGEST');
        $this->em->persist($digest);
        $this->em->flush();

        $created = $this->documents()[0]['changes'];
        self::assertSame(['old' => null, 'new' => 'report.pdf'], $created['name']);

        if (ObservingMiddleware::onDbal3()) {
            self::assertArrayNotHasKey('digest', $created, 'a stream DBAL 3 made of the value was recorded');
            self::assertNotEmpty($this->doubtsOf(AuditsABinary::class));
        } else {
            self::assertSame(['old' => null, 'new' => 'DIGEST'], $created['digest']);
            self::assertSame([], $this->doubtsOf(AuditsABinary::class));
        }

        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, 'OTHER');
        rewind($stream);
        $digest->digest = $stream;
        $digest->name = 'with-stream.pdf';
        $this->em->flush();

        // Not "digest became ''", and not the rename lost with it.
        self::assertSame(['name' => ['old' => 'report.pdf', 'new' => 'with-stream.pdf']], $this->documents()[1]['changes']);
        self::assertNotEmpty($this->doubtsOf(AuditsABinary::class));
        self::assertSame([], array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'binary column')), 'a BINARY declaration was refused');
    }

    public function testAFlushAfterAStreamDoesNotInventTheColumnsOldValue(): void
    {
        // What the row holds after a stream was written to it is not something the history read,
        // and the next flush that changes something else must not say the column moved.
        $this->attachListener(FailurePolicy::Log);
        $this->unownedStatementsAreExpected = true;

        $digest = new AuditsABinary('report.pdf', 'DIGEST');
        $this->em->persist($digest);
        $this->em->flush();

        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, 'OTHER');
        rewind($stream);
        $digest->digest = $stream;
        $this->em->flush();

        $digest->name = 'renamed.pdf';
        $this->em->flush();

        $last = $this->documents()[\count($this->documents()) - 1];
        self::assertSame(['name' => ['old' => 'report.pdf', 'new' => 'renamed.pdf']], $last['changes']);

        // One statement wrote the stream, and one is what the doubt counts - on DBAL 3 the
        // INSERT's value was a stream already, and is a doubt of its own.
        $doubts = $this->doubtsOf(AuditsABinary::class);
        self::assertCount(ObservingMiddleware::onDbal3() ? 2 : 1, $doubts);
        self::assertStringContainsString('for 1 statement(s)', $doubts[\count($doubts) - 1]);
    }

    public function testAValueWrittenAfterAStreamHasNoOldSideNotTheOneBeforeIt(): void
    {
        // A, then a stream B, then C: what the row held before C is B's bytes, which the
        // history never read. Not "A became C", not "'' became C", and not SQL NULL either.
        $this->attachListener(FailurePolicy::Log);
        $this->unownedStatementsAreExpected = true;

        $digest = new AuditsABinary('report.pdf', 'AAAA');
        $this->em->persist($digest);
        $this->em->flush();

        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, 'BBBB');
        rewind($stream);
        $digest->digest = $stream;
        $this->em->flush();

        $digest->digest = 'CCCC';
        $this->em->flush();

        // C's change has no old side the history knows, so it is doubt as B was: no record
        // says digest moved - from A, from '', from NULL or from anything else.
        $later = \array_slice($this->documents(), 1);
        self::assertSame([], array_values(array_filter($later, static fn (array $d): bool => isset($d['changes']['digest']))), 'a change of digest was told with an old side nobody read');
        self::assertCount(ObservingMiddleware::onDbal3() ? 3 : 2, $this->doubtsOf(AuditsABinary::class), 'B and C are doubt, each once');
    }

    /**
     * The doubt the listener says of a class, said as it says every doubt: that the history may
     * be missing what a statement did.
     *
     * @return list<string>
     */
    private function doubtsOf(string $class): array
    {
        return array_values(array_filter($this->logs, static fn (string $line): bool => str_contains($line, 'may be missing what they did') && str_contains($line, $class)));
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
        // every other declaration that cannot be honoured. The bytes are no value the history
        // read - the column is doubt - and the rest of each record is written.
        self::assertSame(
            [['create', ['name' => ['old' => null, 'new' => 'report.pdf']]], ['update', ['name' => ['old' => 'report.pdf', 'new' => 'report-v2.pdf']]]],
            array_map(static fn (array $d): array => [$d['event'], $d['changes']], $this->documents()),
        );
        self::assertNotEmpty($this->doubtsOf(AuditsABlob::class));
        $this->unownedStatementsAreExpected = true;
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
