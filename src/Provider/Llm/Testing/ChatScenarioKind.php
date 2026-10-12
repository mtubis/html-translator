<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Testing;

enum ChatScenarioKind
{
    /** The server answers normally. */
    case Success;

    /** The server answers with an error status; the body is the vendor's JSON error, or raw text (e.g. a proxy page). */
    case HttpError;

    /** A 200 response whose body is not valid JSON. */
    case MalformedBody;

    /** A 200 response with valid JSON in the wrong shape (no choices, empty choices, null content). */
    case UnexpectedShape;

    /** The transport fails before any response (connection refused, timeout). */
    case TransportError;

    /**
     * The transport fails with a Psr\Http\Client\NetworkExceptionInterface whose getRequest() returns the request the
     * adapter sent, credentials included. Added in 0.4: contract tests that match the kinds exhaustively must handle it.
     */
    case NetworkError;
}
