<?php
declare(strict_types=1);

namespace PodcastForge\Segments;

final class SegmentKind
{
    public const INTRO         = 'intro';
    public const CHAPTER_START = 'kapitelbeginn';
    public const BODY          = 'text';
    public const OUTRO         = 'outro';
    public const DISCLOSURE    = 'ki_hinweis';
}
