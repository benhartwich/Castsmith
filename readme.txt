=== Podcast Forge ===
Contributors: benhartwich
Tags: podcast, text to speech, voice clone, podlove, auphonic
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turns a fact script or a post into a finished podcast episode spoken with your own voice clone — checked number by number, with two human approvals.

== Description ==

Podcast Forge produces podcast episodes from text:

* **Sources:** upload a DOCX, paste text, pick a post or page of your site, or add your own source through a hook.
* **Spoken script:** a language model rewrites the fact script for the ear — numbers as words, spoken transitions, a fixed opening and sign-off. Prompts are editable, in German and English.
* **Checks:** a deterministic number gate compares every number, time and date of the source with the spoken script; a second model call looks for changed, missing or invented statements.
* **Approval:** you read, edit and approve the text. An e-mail tells you when it is waiting.
* **Voice:** speech synthesis with your ElevenLabs voice clone, segment by segment, with a growing pronunciation dictionary. Re-record single passages with your own voice if needed.
* **Assembly:** pauses, opener, rotating music bridges between chapters, outro, chapter marks and a WebVTT transcript — with ffmpeg on the server, or in plain PHP with Auphonic adding the music.
* **Publishing:** mastering with Auphonic and a Podlove draft with title, descriptions, chapters, transcript and an AI disclosure. The episode goes online only when you publish it.

Podcast Forge is an open-source project without support. Bug reports and pull requests are welcome on GitHub.

= Requirements =

* Podlove Podcast Publisher
* API keys for Anthropic, ElevenLabs (with a voice clone you have the rights to) and Auphonic
* An encryption key in wp-config.php: `define('AASPF_ENCRYPTION_KEY', '…');` — 32 random bytes, base64 encoded
* Optional: ffmpeg and exec() for mixing the music on your own server

== Installation ==

1. Install Podlove Podcast Publisher.
2. Upload and activate Podcast Forge.
3. Add the encryption key to wp-config.php (see Requirements).
4. Open Podcast Forge → Settings, enter the podcast details and API keys. The health checks show what is still missing.

== Frequently Asked Questions ==

= Does it work without ffmpeg? =

Yes. Segments are then joined in PHP, and Auphonic adds opener, bridges and outro. The setting "Assembly" and a health check show which path is used.

= Which languages are supported? =

The interface is English with a German translation. Prompts and the number check exist for German and English.

= Can I use a voice that is not mine? =

Only if you have the rights to it. The voice clone is created in your own ElevenLabs account; Podcast Forge only uses it.

== External services ==

This plugin connects to the following services. Nothing is sent before you enter the corresponding API key, and each request happens only when the respective step runs (started by you or by the automatic chain you enabled).

= Anthropic (Claude API) =

Used to turn the fact script into a spoken script, to create title, descriptions and chapters, to check the spoken script against the source and to suggest pronunciation rules. Sent: the fact script, the spoken script, your prompts and the list of dictionary terms. Endpoint: api.anthropic.com.
Terms: https://www.anthropic.com/legal/commercial-terms — Privacy: https://www.anthropic.com/legal/privacy

= ElevenLabs =

Used for speech synthesis with your voice clone, for speech-to-speech re-recordings, to maintain the pronunciation dictionary and to show the remaining character quota. Sent: the spoken texts segment by segment, your re-recorded audio, dictionary rules. Endpoint: api.elevenlabs.io.
Terms: https://elevenlabs.io/terms-of-use — Privacy: https://elevenlabs.io/privacy-policy

= Auphonic =

Used to master the assembled episode and, without ffmpeg, to add opener, bridges and outro. Sent: the assembled audio, the music files, title, descriptions, keywords and chapter marks; Auphonic calls back a webhook URL of your site when the production is done. Endpoint: auphonic.com.
Terms: https://auphonic.com/terms_of_service — Privacy: https://auphonic.com/privacy

== Changelog ==

= 0.2.0 =
* First public release: pluggable sources (upload, WordPress post), editable prompts in German and English, English number check, assembly with or without ffmpeg, English interface with German translation.
