# Prompts

One file per prompt and language:

| File | Used for |
|---|---|
| `script.md` | fact script → spoken script (the pronunciation dictionary list is appended automatically) |
| `metadata.md` | title, descriptions, keywords, chapters, pronunciation candidates |
| `factcheck.md` | comparing the spoken script with the source |
| `dictionary.md` | deciding on pronunciation rules and suggesting IPA |

Placeholders filled from the settings: `{podcast}`, `{host}`, `{editor}`, `{sign_off}` (an em dash when empty).

Do not edit these files in place — updates overwrite them. Instead edit a prompt in the backend (*Castsmith → Prompts*), or copy the files to a directory of your own and set it as *Prompt directory* in the settings; a file there wins over the bundled one.

The number rules in `script.md` must match what the number check can read back. Change them only together with `src/Numbers`.
