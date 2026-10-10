# Knowledge added over the API

Since plugin 0.28.0, practice areas and cities can be added to a state's
knowledge pack with `POST /editorial/knowledge/{STATE}/{areas|cities}/{slug}`
(docs/api.md), without a plugin update. The live copy is stored in the
WordPress option `lexranked_knowledge_{STATE}`.

Every entry sent to the API is also committed here, in the same shape as
`GET /editorial/knowledge/{STATE}` returns under `added`, so the additions
have history and can be restored. When an entry moves into the shipped pack
(`wordpress/plugins/lexranked-core/src/Content/knowledge/{STATE}.json`), it is
deleted from the API and from this file.
