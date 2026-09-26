<?php
declare(strict_types=1);

namespace PodcastForge\Source;

use PodcastForge\Db\EpisodeRepository;
use PodcastForge\Db\EpisodeStatus;
use PodcastForge\Settings\Options;
use PodcastForge\Text\SourceDocument;

/**
 * Creates an episode from a fact script that has already been fully read in.
 *
 * For sources that have their text available immediately (upload, post). A source
 * that works in the background creates the episode itself with SOURCE_RUNNING
 * and writes the text and blocks later.
 */
final class EpisodeFactory
{
    public static function fromDocument(
        SourceDocument $document,
        string $sourceType,
        string $sourceRef,
        string $label,
        bool $autoChain
    ): int {
        $id = EpisodeRepository::create([
            'status'          => EpisodeStatus::PARSED,
            'source_type'     => $sourceType,
            'source_ref'      => mb_substr($sourceRef, 0, 64),
            'source_filename' => mb_substr($label, 0, 255),
            'source_text'     => $document->bodyText(),
            'source_blocks'   => (string) wp_json_encode($document->toArray()),
            // The disclosure text is copied along so that a later change to its
            // wording does not rewrite drafts that have already been generated.
            'ai_disclosure_text'     => Options::fill(Options::get('ai_disclosure_text')),
            'ai_disclosure_in_audio' => Options::flag('ai_disclosure_in_audio') ? 1 : 0,
            'auto_chain'             => $autoChain ? 1 : 0,
        ]);

        EpisodeRepository::log($id, 'einlesen', sprintf(
            /* translators: 1: number of blocks, 2: number of headings, 3: number of body text characters, 4: optional " Source: " suffix with the source label, or empty */
            __( '%1$d blocks, including %2$d headings, %3$d characters of body text.%4$s', 'podcast-forge' ),
            count($document->blocks),
            count($document->headings()),
            $document->characterCount(),
            $label !== '' ? __( ' Source: ', 'podcast-forge' ) . $label : ''
        ));

        return $id;
    }
}
