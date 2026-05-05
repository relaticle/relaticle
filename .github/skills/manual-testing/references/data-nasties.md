# Data Nasties Library

Boundary and garbage-data payloads. The skill picks 2-4 relevant payloads per text input on the diff (boundary-value analysis).

## Generic input types

### Text

| Payload | Purpose |
|---|---|
| `` (empty) | Required-field validation |
| ` ` (single space) | Whitespace-trim validation |
| `   leading and trailing   ` | Trim-on-save behavior |
| `<long string of 1024 a's>` | Mid-range length check |
| `<long string of 65536 a's>` | DB column truncation, memory |
| `😀🎉🌍 emoji RTL العربية mix` | Multi-byte / Unicode handling |
| `<script>alert('xss')</script>` | XSS escape verification |
| `<img src=x onerror=alert(1)>` | XSS via attribute injection |
| `'; DROP TABLE users; --` | SQL injection escape verification |
| `' OR '1'='1` | SQL boolean injection |
| `../../../../etc/passwd` | Path traversal (relevant for file-naming fields) |
| `"smart quotes" — em-dash` | Word/Pages paste handling |
| `\0` null byte | Truncation in C-strings (rare in PHP but probe APIs) |

### Email

| Payload | Purpose |
|---|---|
| `a@b` | Minimum valid (per RFC) |
| `test+tag@example.com` | Plus-addressing |
| `用户@example.com` | Internationalized email |
| `<254-char address>` | RFC max length |
| `missing-at-sign` | Format validation |
| `double@@example.com` | Format validation |
| `<script>@example.com` | XSS in email field |

### Number

| Payload | Purpose |
|---|---|
| `0` | Zero handling |
| `-1` | Negative |
| `2147483647` | INT_MAX |
| `2147483648` | INT_MAX + 1 (overflow) |
| `9223372036854775807` | BIGINT_MAX |
| `1.5` | Decimal in int field |
| `$1,234.56` | Currency-formatted |
| `1e10` | Scientific notation |
| `NaN`, `Infinity` | Float edge cases |

### Date / DateTime

| Payload | Purpose |
|---|---|
| `1969-12-31` | Pre-Unix-epoch |
| `2038-01-19 03:14:08` | Y2K38 boundary |
| `2026-02-30` | Invalid date |
| `2024-02-29` | Leap-year Feb 29 |
| `2025-02-29` | Non-leap Feb 29 |
| `9999-12-31` | Far future |
| `12/31/2026` | Locale-formatted (US vs ISO) |
| Unix DST boundary | Server timezone bugs |

### Select / Enum

| Payload | Purpose |
|---|---|
| `<value not in enum>` (via API/MCP fuzzing) | Server-side enum validation |
| `[multiple, values]` (where one expected) | Type coercion |
| Enum value with case mismatch (`ACTIVE` vs `active`) | Case sensitivity |

### File / Image

| Payload | Purpose |
|---|---|
| 0-byte file | Empty handling |
| File 10x the documented max size | Limit enforcement |
| Wrong MIME (`.png` extension, JPG content) | MIME sniffing |
| Double extension (`logo.pdf.exe`) | Filter bypass |
| SVG with `<script>` | XSS via SVG |
| Polyglot (valid PNG + JS) | MIME-confusion |

---

## Custom-fields-specific (Relaticle's 22 types)

Relaticle's `relaticle/custom-fields` package defines 22 field types. The skill loads only the entries matching field types present on the changed model. Run `php artisan tinker --execute "App\Models\<Model>::first()->customFieldValues"` to inspect what's in use.

Common types and their nasties:

### Text-like (Text, Textarea, Markdown, RichEditor)
Use the **Text** payloads above. For Markdown/RichEditor, also test:

| Payload | Purpose |
|---|---|
| `[click me](javascript:alert(1))` | Markdown XSS via link href |
| `<script>alert(1)</script>` rendered raw | HTML in Markdown |
| Image with `onerror` attribute | SVG/HTML injection |

### Number-like (Number, Decimal, Currency)
Use the **Number** payloads above. For Currency, also test:

| Payload | Purpose |
|---|---|
| `9999999999999.99` | Precision limit |
| Mixed currency input (`€1,234.56` in USD field) | Locale parsing |

### Date / DateTime / Time
Use the **Date / DateTime** payloads above.

### Select / MultiSelect / Radio / Checkbox
Use the **Select / Enum** payloads. For MultiSelect, also test:

| Payload | Purpose |
|---|---|
| 100 simultaneously-selected values | Performance / DB limit |
| Same value selected twice | Deduplication |

### Color
| Payload | Purpose |
|---|---|
| `#GGG` | Invalid hex |
| `red` (named color) | Format flexibility |
| `rgb(300, 0, 0)` | Out-of-range RGB |

### URL
Use **Text** payloads + URL-specific:

| Payload | Purpose |
|---|---|
| `javascript:alert(1)` | XSS via URL scheme |
| `data:text/html,<script>alert(1)</script>` | data: URL XSS |
| `http://[2001:db8::1]/` | IPv6 |
| `https://xn--80ak6aa92e.com` | Punycode (lookalike attack) |

### Phone
| Payload | Purpose |
|---|---|
| `+1 (555) 123-4567 ext. 99` | Format flexibility |
| `+99999999999999999` | Length cap |
| `<script>alert(1)</script>` | XSS in phone field |

### File / Image
Use **File / Image** payloads above.

### JSON / Code
| Payload | Purpose |
|---|---|
| `{"a": {"b": {"c": ...}}}` (1000-deep nesting) | Recursion limit |
| `{"$": "ref"}` | JSON Reference / interpolation bugs |
| `{}` empty object | Empty handling |
| `null` | Type coercion |

### Encrypted (any field-type variant)
After saving, **must verify**:
- DB row's column contains ciphertext (not plaintext) — `php artisan tinker --execute 'DB::table("custom_field_values")->where("id", <id>)->value("value");'`
- Decrypted value matches input on read.
- Cross-tenant probe: as Team A, attempt to read Team B's encrypted field — expected: empty/error, never plaintext.

### Conditional / Computed
- Required field hidden after dependency change → does form still validate?
- Computed field stays consistent when its dependency is mutated via API/MCP (not just UI)?

---

## Selection rules

For each text input on the diff, the skill picks **2-4** payloads using this priority:

1. Required boundary (empty + max-length).
2. Format-specific nasty (Unicode if I18n is in scope; XSS if rendered to UI; SQL if uses raw query).
3. One creative payload from the list (rotates between runs to avoid blind spots).

Each picked payload is one matrix cell. Each cell records: payload, observation (rendered output, error message, DB state), oracle violation (if any).
