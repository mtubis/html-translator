<?php

declare(strict_types=1);

namespace HtmlTranslator;

enum IssueType
{
    /** The provider's translations kept failing structural validation. */
    case InvalidStructure;

    /** The provider reported that it could not translate the unit. */
    case ProviderFailure;

    /** The provider returned nothing for the unit. */
    case MissingTranslation;

    /** The TextNodesOnly fallback could not translate some text nodes; they keep their original text. */
    case PartiallyTranslated;
}
