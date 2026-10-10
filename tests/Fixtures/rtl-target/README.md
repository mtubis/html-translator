# rtl-target

Translated to Arabic (`options.json`): `<html lang="ar" dir="rtl">` replaces `lang="en" dir="ltr"`.
A nested `span` with its own `lang="he" dir="rtl"` keeps its attributes and Hebrew text; `bdi` stays inline.
Text is pseudo-translated as usual (the provider is not language-aware).
