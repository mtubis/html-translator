<?php

declare(strict_types=1);

namespace HtmlTranslator\Validation;

enum ViolationType
{
    case InvalidEncoding;
    case EmptyTranslation;
    case RawMarkup;
    case TagsInPlainText;
    case UnknownTag;
    case WrongTagForm;
    case MissingTag;
    case DuplicateTag;
    case UnclosedTag;
    case UnexpectedClosingTag;
    case ImproperNesting;
}
