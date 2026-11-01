<?php

declare(strict_types=1);

namespace App\Message;

/**
 * High-volume analytics message: recorded only while optional recording is on.
 */
final readonly class TrackPageView
{
    public function __construct(
        public string $path,
    ) {
    }
}
