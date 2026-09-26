# Podcast Forge

Turns a fact script, a WordPress post or your own source into a finished podcast episode — spoken with a clone of your own voice, checked number by number, mastered and handed to [Podlove](https://podlove.org/) as a draft. Two steps always stay with a human: approving the text and approving the audio.

Podcast Forge grew out of the monthly sky-preview podcast of an astronomy club and is released as open source in the hope that it is useful to others. **There is no support.** Issues and pull requests are welcome, but nobody is obliged to answer them.

## What it does

1. **Source → fact script.** Upload a DOCX or paste text, pick a post or page of the site, or plug in your own source (see *Sources* below).
2. **Fact script → spoken script.** A language model (Anthropic Claude) rewrites the text for the ear: numbers as words, spoken transitions, a fixed opening and sign-off.
3. **Checks before you approve.** A deterministic number gate compares every number, time and date of the source with the spoken script (German and English). A second model call looks for changed, missing or invented statements. Nothing blocking goes through unconfirmed.
4. **Your approval.** You read, edit and approve the spoken script in WordPress. You get an e-mail when it is waiting.
5. **Speech synthesis** with your ElevenLabs voice clone, segment by segment, with a pronunciation dictionary that grows with every episode. Single segments can be regenerated or re-recorded with your own voice (speech-to-speech).
6. **Assembly.** Segments, pauses, opener, rotating music bridges between chapters, outro, chapter marks and a WebVTT transcript.
7. **Mastering** with Auphonic, then a **Podlove draft** with title, descriptions, chapters, transcript and an AI disclosure.
8. **Your second approval** — the episode goes online only when you publish it.

## Two assembly paths

| | ffmpeg on the server | without ffmpeg (typical shared hosting) |
|---|---|---|
| Joining segments | ffmpeg, pauses filled with room tone | plain PHP, frame by frame; pauses are silent MP3 frames |
| Opener, bridges, outro | mixed by the plugin like a radio feature (bridges fade under the next chapter) | added by Auphonic: intro with overlap and ducking, bridges as inserts, outro |
| Chapter times | calculated | calculated, then taken from the chapter marks Auphonic writes into the file |

The setting *Assembly* chooses automatically; a health check shows which path is active and why.

## Requirements

- WordPress 6.5+, PHP 8.2+ with `sodium`, `dom`, `mbstring`
- [Podlove Podcast Publisher](https://wordpress.org/plugins/podlove-podcasting-plugin-for-wordpress/)
- Accounts and API keys: [Anthropic](https://www.anthropic.com/), [ElevenLabs](https://elevenlabs.io/) (with a voice clone of the host — you need the rights to that voice), [Auphonic](https://auphonic.com/)
- Optional: `ffmpeg`/`ffprobe` and `exec()` or `proc_open()` for the ffmpeg path
- An encryption key in `wp-config.php` for the stored API keys:
  ```php
  define('AASPF_ENCRYPTION_KEY', '…32 random bytes, base64 encoded…');
  ```
  e.g. `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`

## Installation

From a release ZIP: upload it under *Plugins → Add New → Upload*. From source:

```sh
git clone https://github.com/benhartwich/podcast-forge.git wp-content/plugins/podcast-forge
cd wp-content/plugins/podcast-forge && composer install --no-dev
```

Then open *Podcast Forge → Settings*: podcast details (name, host, sign-off, language), API keys, voice, Auphonic preset. The health checks at the top tell you what is still missing.

## Prompts and languages

The prompts live in `prompts/de/` and `prompts/en/` and can be edited in the backend (*Podcast Forge → Prompts*) or overridden by files in a directory of your choice. Placeholders `{podcast}`, `{host}`, `{editor}`, `{sign_off}` are filled from the settings. The interface is English with a German translation; other languages can be added via `languages/podcast-forge.pot`.

## Sources

A source brings an episode to an imported fact script; from there the chain is the same for all. Add-ons register their own:

```php
add_action('podcast_forge_register_sources', static function (): void {
    \PodcastForge\Source\Sources::register(new My\Plugin\CalendarSource());
});
```

Extend `PodcastForge\Source\AbstractSource` and implement `id()`, `label()`, `description()` and `renderStartForm()`; create episodes with `PodcastForge\Source\EpisodeFactory::fromDocument()`. Sources that work in the background can report progress, contribute checklist items, block the approval, add show-notes sections and more — see `src/Source/Source.php`. Further hooks for add-ons: `podcast_forge_option_defaults`, `podcast_forge_settings_fields`, `podcast_forge_settings_sections`, `podcast_forge_overview_cards`, `podcast_forge_overview_panels`, `podcast_forge_health_checks`, `podcast_forge_daily`.

## Development

```sh
composer install
vendor/bin/phpunit
```

See [CONTRIBUTING.md](CONTRIBUTING.md) and [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Privacy

Podcast Forge sends the texts of an episode to Anthropic, the spoken texts (and your re-recordings) to ElevenLabs and the assembled audio with its metadata to Auphonic — only when you start the corresponding step. Details and links to the providers' terms are in `readme.txt` under *External services*.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
