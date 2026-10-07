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
    /** @return iterable<string, array{\Closure(): object, string, string}> */
    public static function binaryColumns(): iterable
    {
        yield 'a BLOB' => [static fn (): object => new AuditsABlob('report.pdf', 'PDF-BYTES'), AuditsABlob::class, 'content'];
        yield 'a BINARY' => [static fn (): object => new AuditsABinary('report.pdf', 'DIGEST'), AuditsABinary::class, 'digest'];
    }

    /**
     * @param \Closure(): object $entity
     */
    #[DataProvider('binaryColumns')]
    public function testTheDeclarationIsRefusedAndSaysWhatToAuditInstead(\Closure $entity, string $class, string $field): void
    {
        $this->attachListener(FailurePolicy::Throw);
        $this->em->persist($entity());

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
