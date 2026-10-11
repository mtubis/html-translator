<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm;

use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\TranslationBatch;
use JsonException;
use stdClass;

/**
 * Reads the answer to a PromptBuilder request (ADR-0011): {"units": [{"id": "…", "text": "…"}]}.
 *
 * Tolerated: a bare list instead of the object, a Markdown code fence or prose around the JSON, a trailing comma.
 * Unit texts are returned as written without surrounding whitespace; checking them is the validator's job. The answer
 * never appears in exception messages: it is untrusted and may repeat customer content.
 */
final class ResponseParser
{
    /** ASCII whitespace as defined by the HTML spec, as in TranslationUnit. */
    private const string HTML_WHITESPACE = " \t\n\f\r";

    /** JSON insignificant whitespace (RFC 8259). */
    private const string JSON_WHITESPACE = " \t\n\r";

    /** Brackets in prose before the JSON (e.g. "{name}") are skipped; the bound keeps scanning linear in the answer. */
    private const int MAX_CANDIDATES = 8;

    /**
     * @throws ProviderException when the answer has no usable JSON, or the JSON has no list of units
     */
    public function parse(ChatResponse $response, TranslationBatch $batch): BatchResult
    {
        try {
            $entries = $this->entries($response->content);
        } catch (ProviderException $exception) {
            if (FinishReason::Length === $response->finishReason) {
                throw new ProviderException('The model answer was cut off at the output token limit; send fewer units per batch.', 0, $exception);
            }

            throw $exception;
        }

        $wanted = [];
        foreach ($batch->units as $unit) {
            $wanted[$unit->id->toString()] = $unit;
        }

        $texts = [];
        $conflicting = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof stdClass) {
                continue;
            }

            $fields = get_object_vars($entry);
            $id = $fields['id'] ?? null;
            if (!\is_string($id) || !isset($wanted[$id])) {
                continue;
            }

            $text = $fields['text'] ?? null;
            if (!\array_key_exists($id, $texts)) {
                $texts[$id] = $text;
            } elseif (\is_string($texts[$id]) && $texts[$id] !== $text) {
                $conflicting[$id] = $id;
            }
        }

        $translations = [];
        $failures = [];
        foreach ($texts as $id => $text) {
            if (isset($conflicting[$id])) {
                $failures[$id] = 'The answer contains the unit more than once, with different texts.';
            } elseif (!\is_string($text)) {
                $failures[$id] = 'The text of the unit in the answer is missing or not a string.';
            } else {
                // The core restores the source's surrounding whitespace; whitespace added by the model would double it.
                $translations[$id] = trim($text, self::HTML_WHITESPACE);
            }
        }

        return new BatchResult($translations, $failures);
    }

    /**
     * Takes the first JSON value in the answer that has the expected shape.
     *
     * @throws ProviderException
     *
     * @return array<mixed>
     */
    private function entries(string $content): array
    {
        $error = null;
        $wrongShape = false;
        $offset = 0;

        for ($candidate = 0; $candidate < self::MAX_CANDIDATES; ++$candidate) {
            $start = $offset + strcspn($content, '{[', $offset);
            if ($start >= \strlen($content)) {
                break;
            }

            // An opening bracket that is never closed may be prose too (e.g. "[see below:").
            $offset = $start + 1;
            $json = $this->scan($content, $start);
            if (null === $json) {
                continue;
            }

            try {
                $decoded = json_decode($json, flags: \JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                // Not JSON (e.g. "{name}" in prose): an opening bracket inside it may still start the answer.
                $error = $exception;

                continue;
            }

            if ($decoded instanceof stdClass) {
                $decoded = get_object_vars($decoded)['units'] ?? null;
            }

            // Objects decode to stdClass, so an array here is always a JSON list.
            if (\is_array($decoded)) {
                return $decoded;
            }

            $wrongShape = true;
            $offset = $start + \strlen($json);
        }

        if ($wrongShape) {
            throw new ProviderException('The model answer has no "units" list.');
        }

        if ($error instanceof JsonException) {
            // JSON error messages name the problem, never the content. Code 0: the code is reserved for HTTP statuses.
            throw new ProviderException(\sprintf('The model answer is not valid JSON: %s (%d bytes).', $error->getMessage(), \strlen($content)), 0, $error);
        }

        throw new ProviderException(\sprintf('The model answer contains no complete JSON value (%d bytes).', \strlen($content)));
    }

    /**
     * The JSON value starting at the opening bracket at $start, up to its closing bracket and without commas right
     * before a closing bracket; null when it is never closed. One pass that skips strings, so linear in the input.
     */
    private function scan(string $content, int $start): ?string
    {
        $length = \strlen($content);
        $depth = 0;
        $comma = null;
        $dropped = [];

        for ($i = $start; $i < $length; ++$i) {
            $i += strspn($content, self::JSON_WHITESPACE, $i);
            if ($i >= $length) {
                return null;
            }

            switch ($content[$i]) {
                case '"':
                    $i = $this->stringEnd($content, $i + 1);
                    if (null === $i) {
                        return null;
                    }

                    $comma = null;

                    break;

                case '{':
                case '[':
                    ++$depth;
                    $comma = null;

                    break;

                case '}':
                case ']':
                    if (null !== $comma) {
                        $dropped[] = $comma;
                        $comma = null;
                    }

                    if (0 === --$depth) {
                        return $this->without($content, $start, $i, $dropped);
                    }

                    break;

                case ',':
                    $comma = $i;

                    break;

                default:
                    $comma = null;
            }
        }

        return null;
    }

    /**
     * Offset of the quote that closes the string whose content starts at $i, or null when it is never closed.
     */
    private function stringEnd(string $content, int $i): ?int
    {
        $length = \strlen($content);

        while (true) {
            $i += strcspn($content, '"\\', $i);
            if ($i >= $length) {
                return null;
            }

            if ('"' === $content[$i]) {
                return $i;
            }

            $i += 2;
        }
    }

    /**
     * @param list<int> $dropped offsets of commas to leave out
     */
    private function without(string $content, int $start, int $end, array $dropped): string
    {
        $json = '';
        foreach ($dropped as $comma) {
            $json .= substr($content, $start, $comma - $start);
            $start = $comma + 1;
        }

        return $json . substr($content, $start, $end - $start + 1);
    }
}
