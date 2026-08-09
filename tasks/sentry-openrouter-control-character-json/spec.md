# Spec

Fix the OpenRouter contract-interpretation failure where strict JSON content contains an unescaped ASCII control character and `json_decode(..., JSON_THROW_ON_ERROR)` throws `JsonException`.

Requirements:
- Accept an otherwise valid JSON object when a provider returns a raw ASCII control character inside a JSON string.
- Escape only raw control characters inside JSON string values. Do not rewrite valid JSON escapes or silently repair other malformed JSON.
- Keep schema validation and all existing current/historical retry policies unchanged.
- Add focused regression tests for raw newline, tab, and low control bytes inside string values and for malformed JSON that must still fail.
- Update nearby implementation documentation.
- Do not deploy or mutate production without separate confirmation.
