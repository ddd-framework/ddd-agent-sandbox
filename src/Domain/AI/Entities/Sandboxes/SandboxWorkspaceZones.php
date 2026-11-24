<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Infrastructure\Libs\Config;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The zones of a workspace and their lifetime. Cleanup is the platform's job, never the agent's: everything is
 * transient unless placed deliberately.
 *
 * - `work/<conversationKey>/`: scratch of one conversation (the default cwd of its shell), 7 days after the
 *   conversation's last activity;
 * - `out/`: results for the operator, 30 days after the last change (7 days after a hand-out the host records);
 * - `inbox/`: uploads, 30 days;
 * - `lib/` and `NOTES.md`: lasting tools, templates, reference data and conventions of the space, never expire
 *   (`lib/` has a quota of its own, 200 MB);
 * - anything else at the root expires like `work/` (7 days after the last activity of the conversation that made it).
 *
 * `/tmp` and `mounts/` are never saved (SandboxWorkspacesService::isExcludedPath()).
 */
class SandboxWorkspaceZones
{
    public const string ZONE_WORK = 'work';

    public const string ZONE_OUT = 'out';

    public const string ZONE_INBOX = 'inbox';

    public const string ZONE_LIB = 'lib';

    public const string ZONE_ROOT = 'root';

    /** @var int Days scratch (work/, root) lasts after the last activity */
    public const int SCRATCH_DAYS = 7;

    /** @var int Days a result (out/) or an upload (inbox/) lasts after its last change */
    public const int RESULT_DAYS = 30;

    /** @var int Days a result lasts after it was handed out */
    public const int HANDED_OUT_DAYS = 7;

    /** @var int The quota of lib/ */
    public const int LIB_QUOTA_BYTES = 200 * 1024 * 1024;

    /** @var string The zone of the scratch folders */
    public const string WORK_DIRECTORY = 'work';

    /** @var string The time zone of the dates the agent reads (AI.Sandbox.runner.timezone overrides it) */
    public const string DISPLAY_TIMEZONE = 'UTC';

    /**
     * @param string $path relative to /workspace
     * @return string one of the ZONE_ constants
     */
    public static function findZoneOfPath(string $path): string
    {
        $path = ltrim($path, '/');
        if ($path === 'NOTES.md' || str_starts_with($path, 'lib/')) {
            return static::ZONE_LIB;
        }
        if (str_starts_with($path, 'out/')) {
            return static::ZONE_OUT;
        }
        if (str_starts_with($path, 'inbox/')) {
            return static::ZONE_INBOX;
        }
        if (str_starts_with($path, static::WORK_DIRECTORY . '/')) {
            return static::ZONE_WORK;
        }
        return static::ZONE_ROOT;
    }

    /**
     * @param string $path
     * @return bool Whether the file is scratch (work/ or the root): the first to go under quota pressure
     */
    public static function isScratch(string $path): bool
    {
        $zone = static::findZoneOfPath($path);
        return $zone === static::ZONE_WORK || $zone === static::ZONE_ROOT;
    }

    /**
     * @param string $path
     * @return string|null the conversation a `work/<conversationKey>/…` path belongs to
     */
    public static function findConversationOfWorkPath(string $path): ?string
    {
        if (preg_match('#^' . static::WORK_DIRECTORY . '/([^/]+)/#', ltrim($path, '/'), $matches) !== 1) {
            return null;
        }
        return $matches[1];
    }

    /**
     * @param string $conversationKey
     * @return string `work/<conversationKey>`, relative to /workspace
     */
    public static function buildWorkDirectoryOfConversation(string $conversationKey): string
    {
        return static::WORK_DIRECTORY . '/' . $conversationKey;
    }

    /**
     * When a saved file expires, as a Unix time; null = never.
     *
     * @param string $path
     * @param SandboxWorkspaceManifestFile $manifestFile
     * @param string[] $conversationsLastActiveAt RFC 3339 by conversation key (the manifest's)
     * @return int|null
     */
    public static function findExpiryOfFile(string $path, SandboxWorkspaceManifestFile $manifestFile, array $conversationsLastActiveAt): ?int
    {
        $zone = static::findZoneOfPath($path);
        if ($zone === static::ZONE_LIB) {
            return null;
        }
        $changedAt = static::toTimestamp($manifestFile->mtime) ?? 0;
        if ($zone === static::ZONE_OUT || $zone === static::ZONE_INBOX) {
            $expiresAt = $changedAt + static::RESULT_DAYS * 86400;
            $handedOutAt = static::toTimestamp($manifestFile->handedOutAt);
            if ($handedOutAt !== null) {
                $expiresAt = min($expiresAt, max($handedOutAt, $changedAt) + static::HANDED_OUT_DAYS * 86400);
            }
            return $expiresAt;
        }
        $conversationKey = static::findConversationOfWorkPath($path) ?? $manifestFile->createdByConversation;
        $lastActivityAt = max(
            $changedAt,
            static::toTimestamp($manifestFile->lastReadAt) ?? 0,
            $conversationKey !== null ? (static::toTimestamp($conversationsLastActiveAt[$conversationKey] ?? null) ?? 0) : 0
        );
        return $lastActivityAt + static::SCRATCH_DAYS * 86400;
    }

    /**
     * What the `changed:` line says about a file changed just now: `expires 29 Nov` (scratch), `kept 30 days`
     * (out/, inbox/), `kept` (lib/, NOTES.md).
     *
     * @param string $path
     * @param int $now Unix time of the change
     * @return string
     */
    public static function buildExpiryLabelOfChange(string $path, int $now): string
    {
        $zone = static::findZoneOfPath($path);
        if ($zone === static::ZONE_LIB) {
            return 'kept';
        }
        if ($zone === static::ZONE_OUT || $zone === static::ZONE_INBOX) {
            return 'kept ' . static::RESULT_DAYS . ' days';
        }
        return 'expires ' . static::formatDisplayDate($now + static::SCRATCH_DAYS * 86400);
    }

    /**
     * @param int $timestamp
     * @return string `29 Nov` in the display time zone (AI.Sandbox.runner.timezone, else UTC)
     */
    public static function formatDisplayDate(int $timestamp): string
    {
        $displayTimezone = trim((string)(Config::get('AI.Sandbox.runner.timezone') ?? ''));
        try {
            $dateTimeZone = new DateTimeZone($displayTimezone !== '' ? $displayTimezone : static::DISPLAY_TIMEZONE);
        } catch (\Exception) {
            $dateTimeZone = new DateTimeZone(static::DISPLAY_TIMEZONE);
        }
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($dateTimeZone)->format('j M');
    }

    /**
     * @param string|null $rfc3339
     * @return int|null
     */
    public static function toTimestamp(?string $rfc3339): ?int
    {
        if ($rfc3339 === null || $rfc3339 === '') {
            return null;
        }
        $timestamp = strtotime($rfc3339);
        return $timestamp === false ? null : $timestamp;
    }
}
