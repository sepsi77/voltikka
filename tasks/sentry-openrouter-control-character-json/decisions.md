# Decisions

## 2026-08-08

- The exception occurs after OpenRouter has returned a successful HTTP response. The outer API response is valid JSON, but `choices.0.message.content` contains an invalid raw ASCII control character in the model-generated JSON text.
- The existing queue behavior classifies this as a transport/runtime failure and retries the complete historical execution. A narrow parser fix is preferable because the content can be recovered without another paid model call when its only defect is an unescaped control character inside a JSON string.
- The normalization is lexical: only bytes `0x00` through `0x1F` that occur inside a quoted JSON string are converted to `\u00XX`. Existing backslash escapes and controls outside strings are not repaired. The normal strict decoder and deterministic validators remain authoritative.
- The client first uses the unchanged strict decoder. It retries decoding with normalization only when PHP reports `JSON_ERROR_CTRL_CHAR`; syntax, depth, UTF-8, and other JSON failures keep their original behavior.
- Focused PHPUnit coverage was added for raw newline, tab, and `0x01` bytes inside one string and for an illegal control byte outside a string. PHP syntax checks and standalone checks for all 32 ASCII control bytes passed. The Laravel test runner could not start because this checkout has no `laravel/vendor/autoload.php`.
