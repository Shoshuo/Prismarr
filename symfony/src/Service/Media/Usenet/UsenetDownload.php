<?php

namespace App\Service\Media\Usenet;

/**
 * One normalized Usenet download — a queue slot or a history entry — shared by
 * the SABnzbd and NZBGet clients so the UI never sees their (very different)
 * raw payloads. SABnzbd talks MB-as-strings + nzo_id; NZBGet talks
 * MB-as-ints + NZBID. Both are funnelled into this shape.
 */
final readonly class UsenetDownload
{
    /** Active, normalized statuses. {@see UsenetStatus} for the canonical set. */
    public function __construct(
        public string $id,
        public string $name,
        /** Normalized status — one of UsenetStatus::* */
        public string $status,
        /** The downloader's own status string, kept for debugging / tooltips. */
        public string $rawStatus,
        public int $sizeBytes,
        public int $remainingBytes,
        /** 0-100, one decimal. */
        public float $percentage,
        public string $category,
        /** Seconds remaining, or null when the downloader can't estimate. */
        public ?int $etaSeconds,
        /** Per-item download speed in bytes/s. SABnzbd has no per-item rate → 0. */
        public int $speedBytes,
        /** Non-null only for failed history entries. */
        public ?string $failMessage,
        public bool $isHistory,
        /** Retry countdown (seconds) while FETCHING an NZB from a URL, else null. */
        public ?int $waitSeconds = null,
        /**
         * Unix epoch the job finished, for history entries only — null when the
         * downloader doesn't report one (and always null for queue slots).
         */
        public ?int $completedAt = null,
    ) {}
}
