# Architecture

## The chain

```
Source ──► fact script (PARSED) ──► spoken script (Redigat) ──► checks ──► text approval
                                                                               │
Podlove draft ◄── Auphonic ◄── assembly (Montage) ◄── speech synthesis ◄───────┘
      │
      └──► audio approval ──► you publish
```

Every step is a background job (Action Scheduler, group `aas-podcast-forge`). Each step is idempotent: it can run again and continues where it stopped. Model calls can go through the Message Batches API (half price); a step then ends with `PendingBatch`, a poll job fetches the result and triggers the step again, which now finds the answer.

## Sources (`src/Source`)

A source creates episodes and brings them to status `PARSED` with `source_text` and `source_blocks` (headings are chapter hints). Sources that need time set `SOURCE_RUNNING` and implement `resume()`. The core ships `UploadSource` (DOCX/text) and `PostSource` (a post rendered with blocks, shortcodes removed, parsed by `Text\HtmlParser`). Everything a source contributes to the UI — progress notice, panel, checklist items, blocking findings, show notes, mail lines — goes through the `Source` interface, so the core never knows a concrete source.

## Checks before the text approval

- **Number gate** (`src/Numbers`): both texts are reduced to typed values — times, days, months, numbers — from digits and number words (German: `GermanNumberParser`/`SpokenExtractor`, English: `EnglishNumberParser`/`EnglishSpokenExtractor`). Invented values block; missing or changed ones block until confirmed. Deterministic on purpose: a rule beats a model's good behaviour.
- **Script guard:** no digits outside break tags, at most five break tags.
- **Fact check:** a model call for changed, missing or invented statements; severe findings must be confirmed.
- **Source findings:** a source may add its own blocking keys.

## Voice

Segments are paragraphs (long ones split at sentence ends); each has a hash over text, seed, model, voice settings and the dictionary rules it touches, so only changed segments are synthesised again. The pronunciation dictionary is a local PLS file (source of truth, version-controllable) mirrored to ElevenLabs as new versions; every synthesis pins a version.

## Assembly (`src/Audio`, `Pipeline\Montage`)

`AudioEngine` picks the path (setting `montage_mode`).

- **ffmpeg:** concat with room tone, loudness matching of re-recorded segments, music mixed by ffmpeg — opener fading under the first words, bridges starting 0.4 s after a chapter and fading under the next one, outro after the last word.
- **PHP:** `Mp3::concat` appends frames; pauses are Layer III frames without data (digital silence) in the segments' own format. `montage_json` stores the plan for Auphonic: intro with overlap and ducking, bridges as inserts at the chapter gaps, outro. Chapter and transcript times are mapped to the final timeline in advance; after production the chapter marks Auphonic wrote into the file (`Id3Chapters`) are taken as the reference, because the API reports chapters unshifted.

## Prompts and language

`Ai\Prompts` resolves each prompt: backend edit (option `aaspf_prompts`) → file in the configured prompt directory → bundled `prompts/<lang>/<name>.md`. The podcast language (`podcast_language`) selects prompts and number parser; the interface language is WordPress's.

## Storage and secrets

Working files live outside the web root if possible (`podcast-forge-data` next to the WordPress directory), otherwise in `wp-content` under a random name (the health check warns). API keys are encrypted with libsodium using `AASPF_ENCRYPTION_KEY` from `wp-config.php`; without the key nothing is stored.

## Two human gates

Nothing reaches the public without two approvals: the text before any audio is produced, and the audio before the Podlove draft is published — which is a manual step in Podlove.
