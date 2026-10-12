<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A PSR-7 stream that can only be read once, like a body streamed from a socket or a generator.
 */
final readonly class NonSeekableStream implements StreamInterface
{
    public function __construct(
        private StreamInterface $inner,
    ) {}

    public function __toString(): string
    {
        return $this->inner->getContents();
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new RuntimeException('The stream is not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('The stream is not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('The stream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->inner->getMetadata($key);
    }
}
