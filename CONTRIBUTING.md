# Contributing

Thanks for looking at Podcast Forge. A few honest words first: this is a side project, maintained when there is time. **There is no support and no guaranteed response** to issues or pull requests. That said, good bug reports and focused pull requests are very welcome.

## Bug reports

Please include the plugin version, WordPress and PHP version, which assembly path is active (health check *Assembly*), the relevant lines of the episode's run log and, if it concerns the number check, a minimal pair of fact script and spoken script that reproduces it. Never post API keys.

## Pull requests

- One topic per pull request.
- Add or adjust unit tests (`tests/Unit`). Code that runs without WordPress should stay testable without WordPress.
- `composer install && vendor/bin/phpunit` must pass.
- User-visible strings are English and wrapped in translation functions with the text domain `podcast-forge`. Update `languages/podcast-forge.pot` with `wp i18n make-pot . languages/podcast-forge.pot --domain=podcast-forge --exclude=vendor,tests,prompts`.
- Keep internal identifiers stable (option names `aaspf_*`, tables `*_aas_*`, status values): existing installations depend on them.
- Explain *why* in comments where the reason is not obvious — this code base prefers a sentence of reasoning over a clever line.

## Adding a source

See *Sources* in the README and `src/Source/Source.php`. A source can live in its own plugin; the core does not need to know about it.

## Adding a language

Translations of the interface: `languages/podcast-forge.pot`. For a podcast language beyond German and English you also need prompts (`prompts/<code>/`) and, for the number check, a parser for number words — see `src/Numbers/EnglishNumberParser.php` and `EnglishSpokenExtractor.php` as a template.
