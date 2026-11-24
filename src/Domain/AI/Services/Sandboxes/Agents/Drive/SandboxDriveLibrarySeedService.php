<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes\Agents\Drive;

use DDD\Domain\AI\Entities\Sandboxes\Drive\SandboxDriveLibrarySeedResult;
use DDD\Domain\CloudDrive\Entities\Nodes\DriveNode;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpace;
use DDD\Domain\CloudDrive\Entities\Spaces\DriveSpaces;
use DDD\Domain\CloudDrive\Entities\Versions\DriveFileVersion;
use DDD\Domain\CloudDrive\Services\AI\DriveSpaceMountResolverService;
use DDD\Domain\CloudDrive\Services\DriveNodesService;
use DDD\Domain\CloudDrive\Services\DriveSpacesService;
use DDD\Domain\CloudDrive\Services\DriveVersionsService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The preload folder of the sandbox (`AI.Sandbox.workspaces.preloadDirectory`) as the library space of the drive:
 * ddd-clouddrive's mount resolver mounts every library space read-only into every drive workspace
 * (drivespaces/library/), and its start.sh runs once at the start of a session. Only new or changed files are written
 * (the drive keeps the old version in its history); files of the library the folder does not have are left alone (a
 * person may have added them). Python build artifacts (`__pycache__/`, `*.pyc`) are never written.
 */
class SandboxDriveLibrarySeedService extends Service
{
    /** @var string A path segment or suffix that marks a build artifact: never seeded */
    public const string BUILD_ARTIFACT_PATTERN = '#(^|/)__pycache__/|\\.py[co]$#';

    /** @var string The comment of a seeded version */
    public const string SEED_COMMENT = 'Sandbox preload seed';

    /**
     * @param string[] $contentsByPath content by path relative to the preload folder
     * @param bool $dryRun count only, write nothing
     * @return SandboxDriveLibrarySeedResult
     * @throws InternalErrorException the library space could not be created
     */
    public function seedLibraryFromContents(array $contentsByPath, bool $dryRun): SandboxDriveLibrarySeedResult
    {
        $sandboxDriveLibrarySeedResult = new SandboxDriveLibrarySeedResult();
        $sandboxDriveLibrarySeedResult->isDryRun = $dryRun;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $librarySpace = $this->findLibrarySpace();
            if ($librarySpace === null && $contentsByPath === []) {
                return $sandboxDriveLibrarySeedResult;
            }
            if ($librarySpace === null && !$dryRun) {
                $librarySpace = $this->createLibrarySpace();
                $sandboxDriveLibrarySeedResult->wasSpaceCreated = true;
            }
            $sandboxDriveLibrarySeedResult->driveSpaceId = $librarySpace !== null ? (int)$librarySpace->id : null;
            ksort($contentsByPath);
            foreach ($contentsByPath as $path => $content) {
                $path = ltrim((string)$path, '/');
                if ($path === '' || preg_match(static::BUILD_ARTIFACT_PATTERN, $path) === 1) {
                    continue;
                }
                $sandboxDriveLibrarySeedResult->fileCount++;
                if ($librarySpace !== null && $this->findStoredSha256($librarySpace, $path) === hash('sha256', (string)$content)) {
                    $sandboxDriveLibrarySeedResult->unchangedCount++;
                    continue;
                }
                $sandboxDriveLibrarySeedResult->writtenPaths[] = $path;
                if ($dryRun || $librarySpace === null) {
                    continue;
                }
                try {
                    $this->getDriveVersionsService()->writeFileContent($librarySpace, $path, (string)$content, null, static::SEED_COMMENT, DriveNode::ORIGIN_UPLOAD);
                } catch (Throwable $throwable) {
                    array_pop($sandboxDriveLibrarySeedResult->writtenPaths);
                    $sandboxDriveLibrarySeedResult->refusedLines[] = $path . ': ' . mb_substr($throwable->getMessage(), 0, 200);
                }
            }
            return $sandboxDriveLibrarySeedResult;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * The first library space (the mount resolver mounts it as `library`); null when there is none.
     */
    public function findLibrarySpace(): ?DriveSpace
    {
        $repoClass = $this->getDriveSpacesService()->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder(true);
        $alias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$alias}.spaceKind = :spaceKind")->setParameter('spaceKind', DriveSpaceMountResolverService::LIBRARY_SPACE_KIND)
            ->orderBy("{$alias}.id", 'ASC')->setMaxResults(1);
        $driveSpaces = $repoClass->find($queryBuilder, false);
        $librarySpace = $driveSpaces instanceof DriveSpaces ? $driveSpaces->first() : null;
        return $librarySpace instanceof DriveSpace ? $librarySpace : null;
    }

    /**
     * @throws InternalErrorException
     */
    protected function createLibrarySpace(): DriveSpace
    {
        $driveSpace = DriveSpace::newInstance();
        $driveSpace->spaceKind = DriveSpaceMountResolverService::LIBRARY_SPACE_KIND;
        $driveSpace->title = (string)(Config::get('AI.Sandbox.drive.libraryTitle') ?? 'Library');
        $driveSpace->audience = DriveSpace::AUDIENCE_INTERNAL;
        $createdSpace = $this->getDriveSpacesService()->createSpace($driveSpace);
        if (!isset($createdSpace->id)) {
            throw new InternalErrorException('The library space could not be created.');
        }
        return $createdSpace;
    }

    /**
     * The sha256 of the file's newest version at the path; null when there is no file there.
     */
    protected function findStoredSha256(DriveSpace $librarySpace, string $path): ?string
    {
        $driveNode = $this->getDriveNodesService()->findNodeByPath($librarySpace, $path);
        if (!$driveNode instanceof DriveNode || !$driveNode->isFile() || $driveNode->isTrashed() || $driveNode->latestVersionId === null) {
            return null;
        }
        $driveFileVersion = $this->getDriveVersionsService()->find((int)$driveNode->latestVersionId, false);
        return $driveFileVersion instanceof DriveFileVersion && $driveFileVersion->sha256 !== null ? strtolower((string)$driveFileVersion->sha256) : null;
    }

    protected function getDriveSpacesService(): DriveSpacesService
    {
        /** @var DriveSpacesService $driveSpacesService */
        $driveSpacesService = DDDService::instance()->getService(DriveSpacesService::class);
        return $driveSpacesService;
    }

    protected function getDriveNodesService(): DriveNodesService
    {
        /** @var DriveNodesService $driveNodesService */
        $driveNodesService = DDDService::instance()->getService(DriveNodesService::class);
        return $driveNodesService;
    }

    protected function getDriveVersionsService(): DriveVersionsService
    {
        /** @var DriveVersionsService $driveVersionsService */
        $driveVersionsService = DDDService::instance()->getService(DriveVersionsService::class);
        return $driveVersionsService;
    }
}
