<?php
declare(strict_types=1);

namespace PodcastForge\Health;

interface CheckInterface
{
    /** Stable identifier, passed via AJAX. */
    public function id(): string;

    /** Label shown in the user interface. */
    public function label(): string;

    public function run(): Result;
}
