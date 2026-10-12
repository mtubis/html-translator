# fragment-table-rows

Table rows rendered without their `<table>`, as a CMS field or template partial may hold them (#42). The fragment is
parsed in a `<tbody>` context, so every `<tr>`, `<th>` and `<td>` stays in the output; a `<body>` context would drop
them and keep only their text. Cell text, the bold number and the `title` attributes are translated; `href` and
`scope` are unchanged.
