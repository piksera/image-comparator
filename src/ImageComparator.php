<?php

namespace SapientPro\ImageComparator;

use GdImage;
use SapientPro\ImageComparator\Enum\ImageRotationAngle;
use SapientPro\ImageComparator\Strategy\AverageHashStrategy;
use SapientPro\ImageComparator\Strategy\HashStrategy;

class ImageComparator
{
    private HashStrategy $hashStrategy;

    public function __construct()
    {
        $this->hashStrategy = new AverageHashStrategy();
    }

    public function setHashStrategy(HashStrategy $hashStrategy): void
    {
        $this->hashStrategy = $hashStrategy;
    }

    /**
     * Hash two images and return an index of their similarly as a percentage.
     *
     * @param  GdImage|string  $sourceImage
     * @param  GdImage|string  $comparedImage
     * @param  ImageRotationAngle  $rotation
     * @param  int  $precision
     * @return float
     * @throws ImageResourceException
     */
    public function compare(
        GdImage|string $sourceImage,
        GdImage|string $comparedImage,
        ImageRotationAngle $rotation = ImageRotationAngle::D0,
        int $precision = 1
    ): float {
        $hash1 = $this->hashImage($sourceImage); // this one should never be rotated
        $hash2 = $this->hashImage($comparedImage, $rotation);

        return $this->compareHashes($hash1, $hash2, $precision);
    }

    /**
     * Hash source image and each image in the array.
     * Return an array of indexes of similarities as a percentage.
     *
     * @param  GdImage|string  $sourceImage
     * @param  (GdImage|string)[]  $images
     * @param  ImageRotationAngle  $rotation
     * @param  int  $precision
     * @return array
     * @throws ImageResourceException
     */
    public function compareArray(
        GdImage|string $sourceImage,
        array $images,
        ImageRotationAngle $rotation = ImageRotationAngle::D0,
        int $precision = 1
    ): array {
        $similarityPercentages = [];

        foreach ($images as $key => $comparedImage) {
            $similarityPercentages[$key] = $this->compare($sourceImage, $comparedImage, $rotation, $precision);
        }

        return $similarityPercentages;
    }

    /**
     * Compare hash strings (no rotation).
     * This assumes the strings will be the same length, which they will be as hashes.
     *
     * @param string $hash1
     * @param string $hash2
     * @param int $precision
     * @return float
     */
    public function compareHashStrings(string $hash1, string $hash2, int $precision = 1): float
    {
        $similarity = strlen($hash1);

        // take the hamming distance between the strings.
        for ($i = 0; $i < strlen($hash1); $i++) {
            if ($hash1[$i] !== $hash2[$i]) {
                $similarity--;
            }
        }

        return round(($similarity / strlen($hash1) * 100), $precision);
    }

    /**
     * Multi-pass comparison - the compared image is being rotated by 90 degrees and compared for each rotation.
     * Returns the highest match after comparing rotations.
     *
     * @param GdImage|string $sourceImage
     * @param GdImage|string $comparedImage
     * @param int $precision
     * @return float
     * @throws ImageResourceException
     */
    public function detect(
        GdImage|string $sourceImage,
        GdImage|string $comparedImage,
        int $precision = 1
    ): float {
        $highestSimilarityPercentage = 0;

        foreach (ImageRotationAngle::cases() as $rotation) {
            $similarity = $this->compare($sourceImage, $comparedImage, $rotation, $precision);

            if ($similarity > $highestSimilarityPercentage) {
                $highestSimilarityPercentage = $similarity;
            }
        }

        return $highestSimilarityPercentage;
    }

    /**
     * Array of images multi-pass comparison
     * The compared image is being rotated by 90 degrees and compared for each rotation.
     * Returns the highest match after comparing rotations for each array element.
     *
     * @param GdImage|string $sourceImage
     * @param (GdImage|string)[] $images
     * @param int $precision
     * @return array
     * @throws ImageResourceException
     */
    public function detectArray(GdImage|string $sourceImage, array $images, int $precision = 1): array
    {
        $similarityPercentages = [];

        foreach ($images as $key => $comparedImage) {
            $similarityPercentages[$key] = $this->detect($sourceImage, $comparedImage, $precision);
        }

        return $similarityPercentages;
    }

    /**
     * Build a perceptual hash out of an image. Just uses averaging because it's faster.
     * The hash is stored as an array of bits instead of a string.
     * http://www.hackerfactor.com/blog/index.php?/archives/432-Looks-Like-It.html
     *
     * @param  GdImage|string  $image
     * @param  ImageRotationAngle  $rotation  Create the hash as if the image were rotated by this value.
     * Default is 0, allowed values are 90, 180, 270.
     * @param int $size the size of the thumbnail created from the original image.
     * The hash will be the square of this (so a value of 8 will build a hash out of 8x8 image, of 64 bits.)
     * @return array
     * @throws ImageResourceException
     */
    public function hashImage(
        GdImage|string $image,
        ImageRotationAngle $rotation = ImageRotationAngle::D0,
        int $size = 8
    ): array {
        $image = $this->normalizeAsResource($image);
        $imageCached = imagecreatetruecolor($size, $size);

        imagecopyresampled($imageCached, $image, 0, 0, 0, 0, $size, $size, imagesx($image), imagesy($image));
        imagecopymergegray($imageCached, $image, 0, 0, 0, 0, $size, $size, 50);

        $width = imagesx($imageCached);
        $height = imagesy($imageCached);

        $pixels = $this->processImagePixels($imageCached, $size, $height, $width, $rotation);

        return $this->hashStrategy->hash($pixels);
    }

    /**
     * Make an image a square and return the resource
     *
     * @param string $image
     * @return GdImage|false
     * @throws ImageResourceException
     */
    public function squareImage(string $image): GdImage|false
    {
        $imageResource = $this->normalizeAsResource($image);

        $width = imagesx($imageResource);
        $height = imagesy($imageResource);

        // calculating the part of the image to use for new image
        if ($width > $height) {
            $x = 0;
            $y = ($width - $height) / 2;
            $xRect = 0;
            $yRect = ($width - $height) / 2 + $height;
            $thumbSize = $width;
        } else {
            $x = ($height - $width) / 2;
            $y = 0;
            $xRect = ($height - $width) / 2 + $width;
            $yRect = 0;
            $thumbSize = $height;
        }

        // copying the part into new image
        $thumb = imagecreatetruecolor($thumbSize, $thumbSize);
        // set background top / left white
        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefilledrectangle($thumb, 0, 0, $thumbSize - 1, $thumbSize - 1, $white);
        imagecopyresampled($thumb, $imageResource, $x, $y, 0, 0, $thumbSize, $thumbSize, $thumbSize, $thumbSize);
        // set background bottom / right white
        imagefilledrectangle($thumb, $xRect, $yRect, $thumbSize - 1, $thumbSize - 1, $white);

        return $thumb;
    }

    /**
     * Return binary string from an image hash created by hashImage()
     * @param  array  $hash
     * @return string
     */
    public function convertHashToBinaryString(array $hash): string
    {
        return implode('', $hash);
    }

    /**
     * Create an image resource from the file.
     * If the resource (GdImage) is supplied - return the resource
     *
     * @param string|GdImage $image - Path to file/filename or GdImage instance
     * @return GdImage
     * @throws ImageResourceException
     */
    private function normalizeAsResource(string|GdImage $image): GdImage
    {
        if ($image instanceof GdImage) {
            return $image;
        }

        $imageData = file_get_contents($image);

        if (false === $imageData) {
            throw new ImageResourceException('Could not create an image resource from file');
        }

        return imagecreatefromstring($imageData);
    }

    private function compareHashes(array $hash1, array $hash2, int $precision): float
    {
        $similarity = count($hash1);

        foreach ($hash1 as $key => $bit) {
            if ($bit !== $hash2[$key]) {
                $similarity--;
            }
        }

        return round(($similarity / count($hash1) * 100), $precision);
    }

    private function processImagePixels(
        GdImage $imageResource,
        int $size,
        int $height,
        int $width,
        ImageRotationAngle $rotation = ImageRotationAngle::D0
    ): array {
        $pixels = [];

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
//                  Instead of rotating the image, we'll rotate the position of the pixels.
//                  This will allow us to generate a hash
//                  that can be used to judge if one image is a rotated version of the other,
//                  without actually creating an extra image resource.
//                  This currently only works at all for 90 degree rotations.
                $pixelPosition = $rotation->rotatePixel($x, $y, $height, $width);

                $rgb = imagecolorsforindex(
                    $imageResource,
                    imagecolorat($imageResource, $pixelPosition['rx'], $pixelPosition['ry'])
                );

                $r = $rgb['red'];
                $g = $rgb['green'];
                $b = $rgb['blue'];

                // rgb to grayscale conversion
                $grayScale = (($r * 0.299) + ($g * 0.587) + ($b * 0.114));
                $grayScale = floor($grayScale);

                $pixels[] = $grayScale;
            }
        }

        return $pixels;
    }
}
