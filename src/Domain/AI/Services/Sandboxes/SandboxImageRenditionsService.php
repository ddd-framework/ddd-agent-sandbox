<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxImageRendition;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use Imagick;
use ImagickException;

/**
 * Downscaled renditions of workspace pictures an agent looks at. Every decode runs through
 * {@see RasterImageDecoder} (JPEG, PNG, GIF, WebP, HEIC only, read from the magic bytes and never from a file name, that
 * coder fixed, resource limits applied), first frame only.
 */
class SandboxImageRenditionsService extends Service
{
    /** @var int JPEG quality of a rendition */
    public const int JPEG_QUALITY = 88;

    /**
     * A rendition to look at: the first frame turned upright, downscaled to the longest edge (never upscaled), PNG when
     * it has transparency, else JPEG. The same bytes give the same rendition.
     *
     * @param string $imageBytes
     * @param int $maxEdgePixels
     * @return SandboxImageRendition
     * @throws BadRequestException The bytes are no picture
     */
    public function renderForViewing(string $imageBytes, int $maxEdgePixels): SandboxImageRendition
    {
        $imagick = $this->readFirstFrame($imageBytes);
        try {
            $imagick->autoOrient();
            $imagick->stripImage();
            $targetWidth = static::computeFitWidth($imagick->getImageWidth(), $imagick->getImageHeight(), $maxEdgePixels);
            $targetHeight = static::computeFitHeight($imagick->getImageWidth(), $imagick->getImageHeight(), $maxEdgePixels);
            if ($targetWidth !== $imagick->getImageWidth() || $targetHeight !== $imagick->getImageHeight()) {
                $imagick->thumbnailImage($targetWidth, $targetHeight);
            }
            $targetMimeType = $imagick->getImageAlphaChannel() ? 'image/png' : 'image/jpeg';
            $this->setOutputFormat($imagick, $targetMimeType);
            $sandboxImageRendition = new SandboxImageRendition();
            $sandboxImageRendition->bytes = $imagick->getImageBlob();
            $sandboxImageRendition->mimeType = $targetMimeType;
            $sandboxImageRendition->width = $imagick->getImageWidth();
            $sandboxImageRendition->height = $imagick->getImageHeight();
            return $sandboxImageRendition;
        } catch (ImagickException $imagickException) {
            throw new BadRequestException('the picture could not be rendered: ' . $imagickException->getMessage());
        } finally {
            $imagick->clear();
        }
    }

    /**
     * The width that fits within `$maxEdge` on both edges, keeping the aspect ratio; never enlarges.
     */
    public static function computeFitWidth(int $width, int $height, int $maxEdge): int
    {
        $width = max(1, $width);
        $height = max(1, $height);
        if ($width <= $maxEdge && $height <= $maxEdge) {
            return $width;
        }
        return max(1, (int)round($width * $maxEdge / max($width, $height)));
    }

    /**
     * The height that fits within `$maxEdge` on both edges, keeping the aspect ratio; never enlarges.
     */
    public static function computeFitHeight(int $width, int $height, int $maxEdge): int
    {
        $width = max(1, $width);
        $height = max(1, $height);
        if ($width <= $maxEdge && $height <= $maxEdge) {
            return $height;
        }
        return max(1, (int)round($height * $maxEdge / max($width, $height)));
    }

    /**
     * @param string $imageBytes
     * @return Imagick The first frame
     * @throws BadRequestException
     */
    protected function readFirstFrame(string $imageBytes): Imagick
    {
        if ($imageBytes === '') {
            throw new BadRequestException('the file is empty');
        }
        $imagick = new Imagick();
        try {
            RasterImageDecoder::readBlob($imageBytes, $imagick);
            if ($imagick->getNumberImages() > 1) {
                $imagick->setIteratorIndex(0);
                $firstFrame = $imagick->getImage();
                $imagick->clear();
                $imagick = $firstFrame;
            }
        } catch (ImagickException $imagickException) {
            $imagick->clear();
            throw new BadRequestException('the bytes are no readable picture (' . $imagickException->getMessage() . ')');
        }
        return $imagick;
    }

    /**
     * @param Imagick $imagick
     * @param string $mimeType image/png or image/jpeg
     * @return void
     * @throws ImagickException
     */
    protected function setOutputFormat(Imagick $imagick, string $mimeType): void
    {
        if ($mimeType === 'image/png') {
            RasterImageDecoder::setOutputFormat($imagick, 'PNG');
            return;
        }
        if ($imagick->getImageAlphaChannel()) {
            $imagick->setImageBackgroundColor('white');
            $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        }
        RasterImageDecoder::setOutputFormat($imagick, 'JPEG');
        $imagick->setImageCompressionQuality(static::JPEG_QUALITY);
    }
}
