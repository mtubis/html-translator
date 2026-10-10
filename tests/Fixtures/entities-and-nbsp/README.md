# entities-and-nbsp

Character references and no-break spaces.
Literal `<g1>`/`<br>` text stays text (ADR-0008), NBSPs survive, the attribute with `&` and quotes is translated, and a paragraph holding only `&nbsp;` is not a unit.
The literal `<g1>` is pseudo-translated as text (`&lt;ĝ1&gt;`); were it mistaken for a pseudo-tag, the test's `issues() === []` check would fail.
