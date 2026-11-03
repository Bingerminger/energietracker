# Antworten eines OpenAI-kompatiblen Diensts (erfunden)

Für `OpenAiParamRetryTest` (v3.10.0, GitHub #41). Die Form folgt der
OpenAI-Schnittstelle (`error.message`, `error.type`, `error.param`,
`error.code`); Texte, Kennungen und Modellnamen sind erfunden.

| Datei | Status | Inhalt |
|---|---|---|
| `temperature-unsupported.json` | 400 | ein Modell nimmt nur seine Standard-Temperatur (`param: temperature`) |
| `max-tokens-unsupported.json` | 400 | ein Modell nimmt `max_completion_tokens` statt `max_tokens` (`param: max_tokens`) |
| `param-null.json` | 400 | Absage ohne `param`; der Text nennt `temperature`, die App wiederholt trotzdem nicht |
| `response-format-unsupported.json` | 400 | ein anderer Parameter (`param: response_format`); keine Wiederholung |
| `answer-length.json` | 200 | Antwort am Budget abgeschnitten (`finish_reason: length`) |
| `answer-classify.json` | 200 | vollständige Antwort auf die Frage nach der Art |
