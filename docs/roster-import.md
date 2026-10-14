# Import roster from a spreadsheet

The office chooses a UTF-8 CSV (or `.txt` CSV), or an unencrypted `.xlsx` workbook. Excel imports read the first worksheet in workbook order, resolved through the workbook relationships, not a guessed `sheet1.xml`. `.xls`, `.xlsm`, encrypted workbooks and embedded VBA are refused with a sentence explaining the supported format.

The column step displays the eight roster fields plus four optional second-guardian fields, the file's column choices, unused columns and its first three nonblank data rows. Unknown or ambiguous headers stay unassigned. Each source column can fill only one field. Class and student first name must have a column before Preview. Optional missing columns become empty strings; existing row refusals remain the import service's responsibility.

## Header matching

`RosterColumnMatcher` is a pure function. It separates camel-case words and adjacent letters/digits, lowercases, collapses whitespace/punctuation, treats `E-mail` as `email`, replaces `one`/`two` with `1`/`2`, and treats parent as guardian. Mother supplies guardian 1 and father guardian 2; the office can change either choice. An unnumbered guardian supplies guardian 1. Named built-in mappings are deliberately empty until a real export header is available.

| Manara field | Synonyms after normalisation |
| --- | --- |
| class | class, class name, homeroom, home room, classroom |
| student_first_name | student first name, first name, given name, student given name |
| student_last_name | student last name, last name, surname, family name, student surname, student family name |
| grade | grade, grade level, year level, student grade |
| guardian_first_name | guardian first name, guardian given name |
| guardian_last_name | guardian last name, guardian surname, guardian family name |
| guardian_email | guardian email, guardian email address |
| guardian_phone | guardian phone, guardian phone number, guardian mobile, guardian mobile number, guardian telephone |

The guardian aliases also accept the corresponding `Parent 1`, `Parent One`, `Guardian 1`, `Mother`, `Parent 2`, `Parent Two`, `Guardian 2` and `Father` prefixes. Two guardian groups become two eight-field import rows with the same student fields; an entirely empty second group creates no extra row. Repeated children on separate source rows continue to work through the existing service.

## Limits and safety

These are resource limits chosen for an office roster, not measurements of a vendor export:

- Upload: 2 MiB for either CSV or XLSX, matching the existing 2,048 KiB request limit.
- ZIP: 8 MiB uncompressed per entry, 32 MiB total, 128 entries. Entry metadata is checked before any entry is inflated. Entries are never extracted to disk.
- Worksheet/CSV: 20,000 source data rows plus one header, 128 columns. XLSX row and cell positions are checked as well as actual row/cell counts.
- XML: `LIBXML_NONET`, no DTD loading or entity substitution; reject DOCTYPE/entity declarations, including UTF-16 declarations detected through XMLReader. No huge-parser option.
- Shared and inline strings include rich-text runs but exclude phonetic annotations. Numbers and booleans retain their cached values. Formulas are never evaluated. Date-styled serial values use the workbook's 1900/1904 date system and become `Y-m-d` in UTC; custom date formats and built-in date formats are supported. Spreadsheet error cells are refused.

20,000 source rows can become up to 40,000 service rows when every child has two guardians. This is bounded and all-or-nothing through the existing service. Preview line numbers refer to those expanded service rows, just as the service numbers its input; they are not Excel cell addresses.

## Storage and preview authority

`roster_import_mappings` stores only a SHA-256 signature of the ordered normalised header list and JSON field-to-column indices. The unique key is `(masjid_id, header_signature)`, with an auto-increment primary key. `RosterImportMapping` uses `BelongsToMasjid`, including server-derived tenant stamping. No file bytes, names, email addresses, sample cells or raw headers are persisted. Staging scrub drops mapping rows.

Confirmed choices are remembered when the office presses Preview, even if a roster row is refused. Merely reading columns writes nothing. A different header shape or school never recalls those choices. A later saved mapping does not change an existing preview: its authenticated receipt binds the exact file bytes, mapping, format, school, administrator and expiry. Commit uses that receipt's mapping, reads the uploaded file again, re-plans against current records and calls the existing service unchanged. CSV clients that do not supply a mapping retain the original reader.

`RosterImportService` is unchanged, including email normalisation, exact email identity matching, existing refusals, consent/login exclusions, no class creation and all-or-nothing writes. Generated template parity and two-guardian workbook tests cover preview and commit.

## Known inherited limitation

The existing service accepts missing name values at preview but writes NULL for blank student or guardian last names, while `contacts` requires non-null names. Such a commit can still return 500. This build preserves that behavior under the explicit service-preservation scope; it does not invent a name or change an existing refusal. Supply name columns with values before importing. The reproduced failure was a blank guardian last name in the generated workbook; both blank-name branches and the schema were read directly.

No real export from any vendor has been verified. All new examples are generated, made-up test files. The real-export walk and office guide are separate follow-up work.

## HTTP contract

All three POST routes use the existing signed-in admin, CRM, school-records capability, tenant and `manage contacts` fences. They share the 2 MiB multipart file envelope.

- `.../records/roster-import/columns`: upload `file`; returns `status: success` and `data: { headers, samples, mapping, required, remembered }`. Each mapping value is a zero-based source column index or null. No roster or mapping writes.
- `.../records/roster-import/preview`: upload `file` and JSON-string `mapping`; returns the existing preview payload and cautions. Matching or file-reader problems return 422, with plain sentences in `data.mapping`. The original CSV-only request without `mapping` still uses the original reader/refusal bag.
- `.../records/roster-import`: upload the same `file` and preview `receipt`; the receipt supplies mapping and format. Posted mapping choices are ignored. The existing 201 counts, refusal/empty/expiry/file/user/school guards and re-planning remain unchanged.

A file-reader refusal from `columns` uses 422 and `data.file`. Required columns are returned explicitly rather than inferred from data. Mapping storage is described above; it is not an API for retrieving another school's saved choices.
