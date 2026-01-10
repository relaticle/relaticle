<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

final readonly class AvatarService
{
    /**
     * Minimum contrast ratio for WCAG AA compliance.
     */
    private const float MINIMUM_CONTRAST_RATIO = 4.5;

    /**
     * @param  array<int, string>  $backgroundColors
     */
    public function __construct(
        private Cache $cache,
        private int $cacheTtl = 604800,
        private string $defaultTextColor = '#FFFFFF',
        private string $defaultBgColor = '#3182CE',
        private array $backgroundColors = [],
    ) {}

    /**
     * Generate an SVG avatar for a user with manual color selection.
     *
     * @param  string  $name  The user's name
     * @param  int  $size  The size of the avatar in pixels
     * @param  string|null  $bgColor  Optional custom background color (hex format)
     * @param  string|null  $textColor  Optional custom text color (hex format)
     * @param  int  $initialCount  Number of initials to show (1 or 2)
     * @return string The URL to the SVG avatar
     */
    public function generate(
        string $name,
        int $size = 64,
        ?string $bgColor = null,
        ?string $textColor = null,
        int $initialCount = 2
    ): string {
        $bgColor = $this->validateColor($bgColor) ? $bgColor : null;
        $textColor = $this->validateColor($textColor) ? $textColor : null;

        // Add custom colors to cache key if provided
        $bgColorKey = in_array($bgColor, [null, '', '0'], true) ? '' : "_bg{$bgColor}";
        $textColorKey = in_array($textColor, [null, '', '0'], true) ? '' : "_txt{$textColor}";
        $cacheKey = 'avatar_'.hash('sha256', "{$name}_{$size}{$bgColorKey}{$textColorKey}_initials{$initialCount}");

        return $this->cache->remember(
            $cacheKey,
            $this->cacheTtl,
            fn (): string => $this->createSvgDataUrl($name, $size, $bgColor, $textColor, $initialCount)
        );
    }

    /**
     * Generate an SVG avatar with automatically selected colors.
     *
     * @param  string  $name  The user's name
     * @param  int  $size  The size of the avatar in pixels
     * @param  int  $initialCount  Number of initials to show (1 or 2)
     * @return string The URL to the SVG avatar
     */
    public function generateAuto(string $name, int $size = 64, int $initialCount = 2): string
    {
        $cacheKey = 'avatar_auto_'.hash('sha256', "{$name}_{$size}_initials{$initialCount}");

        return $this->cache->remember(
            $cacheKey,
            $this->cacheTtl,
            function () use ($name, $size, $initialCount): string {
                // Analyze name characteristics
                $nameAnalysis = $this->analyzeNameCharacteristics($name);

                // Generate a deterministic hue based on the name
                $hue = $this->getHueFromName($name);

                // Adjust saturation and lightness based on name characteristics
                $saturation = 65 + $nameAnalysis['adjustment'];
                $lightness = 85 - ($nameAnalysis['adjustment'] / 2);

                // Ensure values stay within reasonable ranges
                $saturation = max(50, min(75, $saturation));
                $lightness = max(75, min(90, $lightness));

                // Generate a pastel background color with adjusted parameters
                $bgColor = $this->hslToHex($hue, $saturation, $lightness);

                // Generate a darker text color with proper contrast
                $textColor = $this->generateContrastingTextColor($bgColor);

                return $this->createSvgDataUrl($name, $size, $bgColor, $textColor, $initialCount);
            }
        );
    }

    /**
     * Create the SVG data URL for an avatar.
     */
    private function createSvgDataUrl(
        string $name,
        int $size,
        ?string $bgColor,
        ?string $textColor,
        int $initialCount = 2
    ): string {
        $initials = $this->getInitials($name, $initialCount);
        $backgroundColor = $bgColor ?? $this->getBackgroundColor($name);
        $textFillColor = $textColor ?? $this->defaultTextColor;

        // Ensure there's enough contrast in auto mode
        if ($bgColor !== null && $textColor !== null && ! $this->hasEnoughContrast($backgroundColor, $textFillColor)) {
            $textFillColor = $this->generateContrastingTextColor($backgroundColor);
        }

        $svg = $this->generateSvg($initials, $backgroundColor, $textFillColor, $size);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Validate if a color string is a valid hex or RGB color.
     */
    public function validateColor(?string $color): bool
    {
        if ($color === null) {
            return false;
        }

        // Validate hex color format (#RGB or #RRGGBB)
        if (preg_match('/^#([A-Fa-f0-9]{3}){1,2}$/', $color)) {
            return true;
        }

        // Validate rgb() and rgba() formats
        return (bool) preg_match('/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*[\d.]+\s*)?\)$/', $color);
    }

    /**
     * Get the initials from a name.
     * Enhanced to better handle various name formats and cultural patterns.
     *
     * @param  string  $name  The name to extract initials from
     * @param  int  $initialCount  The number of initials to return (1 or 2)
     * @return string The extracted initials
     */
    private function getInitials(string $name, int $initialCount = 2): string
    {
        // Normalize count to either 1 or 2
        $count = ($initialCount === 1) ? 1 : 2;

        // Normalize and trim the name to handle special characters better
        $name = trim($name);
        $normalizedName = Str::ascii($name);
        $nameParts = array_values(array_filter(preg_split('/[\s\-_\.]+/', $normalizedName)));

        if ($nameParts === []) {
            return '?';
        }

        // For single initial mode, just return the first letter of the first name part
        if ($count === 1) {
            return Str::upper(substr($nameParts[0], 0, 1));
        }

        // Single word name handling
        if (count($nameParts) === 1) {
            $singleName = $nameParts[0];
            $nameLength = Str::length($singleName);

            // Handle very short names
            if ($nameLength <= 1) {
                return Str::upper($singleName);
            }

            // Handle common prefixes in surnames (Mc, Mac, O', etc.)
            if ($nameLength >= 3) {
                if (Str::startsWith(Str::lower($singleName), ['mc', 'mac'])) {
                    return Str::upper(substr($singleName, 0, 1).substr($singleName, 2, 1));
                }

                if (Str::startsWith(Str::lower($singleName), "o'")) {
                    return Str::upper(substr($singleName, 0, 1).substr($singleName, 2, 1));
                }
            }

            // Default: For single names with at least 2 characters, return first two
            return Str::upper(substr($singleName, 0, 2));
        }

        // Handle multi-part names

        // Check for compound last names (with hyphens, even if normalized)
        $lastPart = end($nameParts);
        if (str_contains($name, '-') && ! in_array('-', $nameParts)) {
            // Original name had hyphens, try to split the last part
            $possibleCompound = array_filter(explode('-', $name));
            if (count($possibleCompound) >= 2) {
                return Str::upper(substr($nameParts[0], 0, 1).
                    substr(end($possibleCompound), 0, 1));
            }
        }

        // Handle names with particles or prefixes (von, van, de, etc.)
        $prefixes = ['van', 'von', 'de', 'la', 'den', 'der', 'di', 'le', 'da'];
        if (count($nameParts) >= 3 && in_array(Str::lower($nameParts[count($nameParts) - 2]), $prefixes)) {
            // Use first name and the actual surname after the prefix
            return Str::upper(substr($nameParts[0], 0, 1).substr($lastPart, 0, 1));
        }

        // Standard case: first and last parts
        return Str::upper(substr($nameParts[0], 0, 1).substr($lastPart, 0, 1));
    }

    /**
     * Get a deterministic background color based on the name.
     */
    private function getBackgroundColor(string $name): string
    {
        // If a default background color is specified, use that
        if ($this->defaultBgColor !== '' && $this->defaultBgColor !== '0') {
            return $this->defaultBgColor;
        }

        // If no background colors provided, return a safe default
        if ($this->backgroundColors === []) {
            return '#3182CE'; // A nice blue as fallback
        }

        // Use more stable hash function
        $hash = crc32(Str::ascii($name));
        $index = $hash % count($this->backgroundColors);

        return $this->backgroundColors[$index];
    }

    /**
     * Generate SVG markup for the avatar.
     */
    private function generateSvg(string $initials, string $bgColor, string $textColor, int $size): string
    {
        // Adjust font size based on initials length
        $fontSize = strlen($initials) > 1 ? 44 : 48;

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" viewBox="0 0 100 100">
            <rect x="0" y="0" width="100" height="100" fill="{$bgColor}" />
            <text x="50%" y="50%" alignment-baseline="middle" dominant-baseline="middle" text-anchor="middle"
                  dy=".1em" fill="{$textColor}" font-family="Arial, Helvetica, sans-serif"
                  font-size="{$fontSize}" font-weight="medium" letter-spacing="-1">
                {$initials}
            </text>
        </svg>
        SVG;
    }

    /**
     * Generate a deterministic hue value from a name.
     */
    private function getHueFromName(string $name): int
    {
        // Use a combination of hashing techniques for better distribution
        $hash1 = crc32(Str::ascii($name));
        $hash2 = crc32(strrev(Str::ascii($name)));

        // Blend the two hashes for better distribution across the color wheel
        $blendedHash = ($hash1 + $hash2) / 2;

        // Map to hue values, avoiding too much clustering around certain hues
        $hue = $blendedHash % 360;

        return $hue;
    }

    /**
     * Generate a contrasting text color for a background.
     */
    private function generateContrastingTextColor(string $bgColor): string
    {
        // Convert the background color to HSL
        [$h, $s, $l] = $this->hexToHsl($bgColor);

        // For darker background, use lighter text
        if ($l < 60) {
            return '#FFFFFF';
        }

        // For lighter background, use a darker shade of the same hue
        // Adjust saturation and lightness for better contrast
        $contrastingSaturation = min(95, $s + 20);
        $contrastingLightness = max(15, $l - 55);

        return $this->hslToHex($h, $contrastingSaturation, $contrastingLightness);
    }

    /**
     * Convert HSL to hex color.
     */
    private function hslToHex(int $h, float $s, float $l): string
    {
        $h /= 360;
        $s /= 100;
        $l /= 100;

        if (abs($s) < 0.001) {  // Fixed: Changed strict equality to approximate comparison for float
            $r = $g = $b = $l;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $r = $this->hueToRgb($p, $q, $h + 1 / 3);
            $g = $this->hueToRgb($p, $q, $h);
            $b = $this->hueToRgb($p, $q, $h - 1 / 3);
        }

        return sprintf('#%02x%02x%02x',
            (int) round($r * 255),
            (int) round($g * 255),
            (int) round($b * 255)
        );
    }

    /**
     * @return int[]
     */
    private function hexToHsl(string $hexColor): array
    {
        // Remove # if present
        $hex = ltrim($hexColor, '#');

        // Handle both shorthand and regular hex formats
        if (strlen($hex) === 3) {
            $r = hexdec(str_repeat(substr($hex, 0, 1), 2)) / 255;
            $g = hexdec(str_repeat(substr($hex, 1, 1), 2)) / 255;
            $b = hexdec(str_repeat(substr($hex, 2, 1), 2)) / 255;
        } else {
            $r = hexdec(substr($hex, 0, 2)) / 255;
            $g = hexdec(substr($hex, 2, 2)) / 255;
            $b = hexdec(substr($hex, 4, 2)) / 255;
        }

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);

        $h = 0;
        $s = 0;
        $l = ($max + $min) / 2;

        if ($max !== $min) {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

            switch ($max) {
                case $r:
                    $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
                    break;
                case $g:
                    $h = ($b - $r) / $d + 2;
                    break;
                case $b:
                    $h = ($r - $g) / $d + 4;
                    break;
            }

            $h /= 6;
        }

        return [
            (int) round($h * 360),  // Convert to 0-359 range
            (int) round($s * 100),  // Convert to 0-100 range
            (int) round($l * 100),   // Convert to 0-100 range
        ];
    }

    /**
     * Helper function for HSL to RGB conversion.
     */
    private function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }

        return $p;
    }

    /**
     * Calculate the contrast ratio between two colors.
     */
    private function calculateContrastRatio(string $color1, string $color2): float
    {
        $lum1 = $this->getRelativeLuminance($color1);
        $lum2 = $this->getRelativeLuminance($color2);

        $lighter = max($lum1, $lum2);
        $darker = min($lum1, $lum2);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Calculate the relative luminance of a color.
     */
    private function getRelativeLuminance(string $hexColor): float
    {
        $hex = ltrim($hexColor, '#');

        // Handle both shorthand and regular hex formats
        if (strlen($hex) === 3) {
            $r = hexdec(str_repeat(substr($hex, 0, 1), 2)) / 255;
            $g = hexdec(str_repeat(substr($hex, 1, 1), 2)) / 255;
            $b = hexdec(str_repeat(substr($hex, 2, 1), 2)) / 255;
        } else {
            $r = hexdec(substr($hex, 0, 2)) / 255;
            $g = hexdec(substr($hex, 2, 2)) / 255;
            $b = hexdec(substr($hex, 4, 2)) / 255;
        }

        // Transform sRGB values
        $r = $r <= 0.03928 ? $r / 12.92 : (($r + 0.055) / 1.055) ** 2.4;
        $g = $g <= 0.03928 ? $g / 12.92 : (($g + 0.055) / 1.055) ** 2.4;
        $b = $b <= 0.03928 ? $b / 12.92 : (($b + 0.055) / 1.055) ** 2.4;

        // Calculate luminance
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Check if two colors have enough contrast for accessibility.
     */
    private function hasEnoughContrast(string $color1, string $color2): bool
    {
        return $this->calculateContrastRatio($color1, $color2) >= self::MINIMUM_CONTRAST_RATIO;
    }

    /**
     * @return array<string, float|int>
     */
    private function analyzeNameCharacteristics(string $name): array
    {
        $normalizedName = Str::ascii(trim($name));

        // Handle empty names
        if (blank($normalizedName)) {
            return [
                'length' => 0,
                'vowelRatio' => 0.5,
                'uniqueness' => 0,
                'adjustment' => 0,
            ];
        }

        // Calculate characteristics
        $length = mb_strlen($normalizedName);
        $lengthFactor = min(10, max(1, $length)) / 10;

        // Count vowels and consonants more accurately
        $vowels = preg_match_all('/[aeiouàáâãäåæèéêëìíîïòóôõöøùúûüýÿ]/i', $normalizedName, $matches);
        $consonants = preg_match_all('/[bcdfghjklmnpqrstvwxyzçñß]/i', $normalizedName, $matches);

        // Names with more vowels tend to be "softer" - adjust colors accordingly
        $vowelRatio = $vowels / ($vowels + $consonants !== 0 ? $vowels + $consonants : 1);

        // More sophisticated uniqueness calculation
        $uniqueness = $this->calculateUniquenessScore($normalizedName);

        // Calculate overall adjustment (-10 to +10 range)
        $adjustment = (($lengthFactor - 0.5) * 10) +  // Length factor
            ((0.5 - $vowelRatio) * 10) +    // Vowel ratio (fewer vowels = higher adjustment)
            ($uniqueness - 5);              // Uniqueness factor normalized to -5 to +5 range

        // Ensure the adjustment stays within reasonable bounds
        $adjustment = max(-10, min(10, $adjustment));

        return [
            'length' => $length,
            'vowelRatio' => $vowelRatio,
            'uniqueness' => $uniqueness,
            'adjustment' => $adjustment,
        ];
    }

    /**
     * Calculate a uniqueness score for a name.
     *
     * @param  string  $name  The normalized name to analyze
     * @return float A uniqueness score from 0 to 10
     */
    private function calculateUniquenessScore(string $name): float
    {
        if ($name === '' || $name === '0') {
            return 0;
        }

        $name = strtolower($name);
        $chars = str_split($name);
        $charFrequency = array_count_values($chars);
        $uniqueChars = count($charFrequency);

        // Base uniqueness on ratio of unique characters
        $uniquenessBase = $uniqueChars / mb_strlen($name) * 5;

        // Additional points for rare letters
        $rarityScore = 0;
        $rareLetters = ['q' => 1.5, 'x' => 1.5, 'z' => 1.5, 'j' => 1, 'k' => 0.8, 'w' => 0.8, 'y' => 0.8, 'v' => 0.7];

        foreach ($charFrequency as $char => $freq) {
            // Award points for rare letters
            if (isset($rareLetters[$char])) {
                $rarityScore += $rareLetters[$char];
            }

            // Penalize for highly repetitive patterns
            if ($freq > 2 && ctype_alpha((string) $char)) {
                $rarityScore -= ($freq - 2) * 0.5;
            }
        }

        // Combine scores and ensure within 0-10 range
        $finalScore = $uniquenessBase + $rarityScore;

        return max(0, min(10, $finalScore));
    }
}
