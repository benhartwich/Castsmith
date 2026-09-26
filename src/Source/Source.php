<?php
declare(strict_types=1);

namespace PodcastForge\Source;

/**
 * Where the text of an episode comes from.
 *
 * The core only knows this interface. A source creates episodes and
 * takes them as far as the imported fact script (status PARSED); from there
 * on the chain is the same for all sources: edited draft, checks, text approval,
 * audio, Auphonic, Podlove. If a source needs time before that — research,
 * model calls — it sets SOURCE_RUNNING and reports back via resume()
 * when a step is stuck.
 *
 * The core ships with two sources (fact script upload, WordPress
 * post); further ones come from add-ons via the hook
 * `podcast_forge_register_sources`. Most methods have a neutral default in
 * AbstractSource; a simple source only overrides
 * id(), label(), description() and renderStartForm().
 */
interface Source
{
    /** Identifier, stored in the `source_type` column. Lowercase letters, a–z, 0–9, hyphen. */
    public function id(): string;

    /** Short name for lists and the header line of an episode. */
    public function label(): string;

    /** One sentence for the "New episode" page. */
    public function description(): string;

    /** The form used to create an episode from this source. */
    public function renderStartForm(): void;

    /** Does the source prepare the text in the background (its own "Sources" station in the workflow)? */
    public function preparesText(): bool;

    /**
     * Heading and text while the source is working (status SOURCE_RUNNING).
     *
     * @param array<string,mixed> $episode
     *
     * @return array{0:string,1:string}
     */
    public function runningNotice(array $episode): array;

    /** After this many seconds without progress, SOURCE_RUNNING is considered stuck. 0: never. */
    public function staleAfterSeconds(): int;

    /** Resumes a stuck or failed preparation. Returns: what happens. */
    public function resume(int $episodeId): string;

    /**
     * Dedicated tab in the episode view (title), or '' for none.
     *
     * @param array<string,mixed> $episode
     */
    public function panelTitle(array $episode): string;

    /** @param array<string,mixed> $episode */
    public function renderPanel(array $episode): void;

    /**
     * Sidebar of the episode view; leave empty for none.
     *
     * @param array<string,mixed> $episode
     */
    public function renderAside(array $episode): void;

    /**
     * The "Source" figure in the episode view: [title, value, subline] or null for the default.
     *
     * @param array<string,mixed> $episode
     *
     * @return array{0:string,1:string,2:string}|null
     */
    public function figure(array $episode): ?array;

    /**
     * Additional checklist rows before text approval: [tone ok|warn|fail, title, text].
     *
     * @param array<string,mixed> $episode
     * @param list<string>        $acknowledged overridden keys
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    public function checklist(array $episode, array $acknowledged): array;

    /**
     * Keys of source findings that block approval until they are overridden.
     *
     * @param array<string,mixed> $episode
     *
     * @return list<string>
     */
    public function blockingKeys(array $episode): array;

    /**
     * Additional HTML for the show notes (source references), or ''.
     *
     * @param array<string,mixed> $episode
     */
    public function shownotesHtml(array $episode): string;

    /**
     * Additional lines for the "Text awaiting approval" e-mail.
     *
     * @param array<string,mixed> $episode
     *
     * @return list<string>
     */
    public function mailLines(array $episode): array;

    /**
     * Short name of the episode for lists and e-mail subjects, or '' to use the title.
     *
     * @param array<string,mixed> $episode
     */
    public function shortName(array $episode): string;
}
