<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Domain\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceActivityRecord;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceManifest;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceZones;
use PHPUnit\Framework\TestCase;

class SandboxWorkspaceManifestTest extends TestCase
{
    public function testTheStoredFormRoundTripsAndKeepsTheOptionalKeys(): void
    {
        $storedManifest = [
            'version' => 3,
            'savedAt' => '2026-11-24T10:00:00Z',
            'files' => [
                'out/a.xlsx' => ['sha256' => 'aa', 'size' => 10, 'mtime' => '2026-11-24T09:00:00Z', 'mode' => '0644', 'createdByConversation' => 'conversation-1'],
                'NOTES.md' => ['sha256' => 'bb', 'size' => 5, 'mtime' => '2026-11-24T08:00:00Z', 'mode' => '0644'],
                'broken' => ['size' => 1],
            ],
            'conversationsLastActiveAt' => ['conversation-1' => '2026-11-24T09:00:00Z'],
        ];
        $sandboxWorkspaceManifest = SandboxWorkspaceManifest::fromArray($storedManifest);
        self::assertSame(2, $sandboxWorkspaceManifest->countFiles());
        self::assertSame(15, $sandboxWorkspaceManifest->sumBytes());
        self::assertTrue($sandboxWorkspaceManifest->holdsContent('bb'));
        self::assertSame('out/a.xlsx', $sandboxWorkspaceManifest->findRecentlyChangedFiles(1)[0]->path);
        unset($storedManifest['files']['broken']);
        self::assertSame($storedManifest, json_decode((string)json_encode($sandboxWorkspaceManifest->toArray()), true));
        self::assertSame(5, $sandboxWorkspaceManifest->sumBytesOfZone(SandboxWorkspaceZones::ZONE_LIB));
    }

    public function testAnEmptyManifestIsStoredAsObjects(): void
    {
        self::assertSame('{"version":0,"savedAt":null,"files":{},"conversationsLastActiveAt":{}}', json_encode((new SandboxWorkspaceManifest())->toArray()));
    }

    public function testActivityMovesOnlyForward(): void
    {
        $sandboxWorkspaceManifest = SandboxWorkspaceManifest::fromArray(['files' => ['a' => ['sha256' => 'x', 'lastReadAt' => '2026-11-24T10:00:00Z']]]);
        $activityRecord = new SandboxWorkspaceActivityRecord();
        $activityRecord->filesLastReadAt = ['a' => '2026-11-23T10:00:00Z', 'missing' => '2026-11-25T10:00:00Z'];
        self::assertFalse($sandboxWorkspaceManifest->mergeActivity($activityRecord));
        $activityRecord->conversationsLastActiveAt = ['conversation-2' => '2026-11-24T11:00:00Z'];
        self::assertTrue($sandboxWorkspaceManifest->mergeActivity($activityRecord));
    }

    public function testZonesAndLabels(): void
    {
        self::assertSame(SandboxWorkspaceZones::ZONE_WORK, SandboxWorkspaceZones::findZoneOfPath('work/conversation-1/x'));
        self::assertSame('conversation-1', SandboxWorkspaceZones::findConversationOfWorkPath('work/conversation-1/x'));
        self::assertSame('kept', SandboxWorkspaceZones::buildExpiryLabelOfChange('lib/a.py', 0));
        self::assertSame('kept 30 days', SandboxWorkspaceZones::buildExpiryLabelOfChange('out/a.xlsx', 0));
        self::assertSame('expires 8 Jan', SandboxWorkspaceZones::buildExpiryLabelOfChange('a.txt', 0));
    }
}
