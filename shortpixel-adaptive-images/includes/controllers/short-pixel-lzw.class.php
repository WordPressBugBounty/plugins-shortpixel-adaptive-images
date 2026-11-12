<?php
/**
 * ShortPixelLzw: LZW string compression helper.
 */

/**
 * LZW compressor/decompressor in the global namespace.
 *
 * Dead code: no file `require`s this file and nothing references the class, so it is
 * never loaded at runtime. Methods have no visibility keyword (public) and the class holds
 * no state.
 *
 * @todo ShortPixelLzw is never loaded and its two halves are incompatible: compress() emits
 *       raw `chr()` bytes (codes above 255 wrap modulo 256, so the output cannot be decoded)
 *       while decompress() expects a comma-separated list of decimal codes and its dictionary
 *       has no entry 0. Delete the file.
 */
class ShortPixelLzw
{
    /**
     * LZW-compresses a string into a string of `chr()`-encoded codes.
     *
     * Iterates over byte positions (strlen) but reads characters with charAt(), which uses
     * multibyte positions, so multibyte input is read misaligned and positions past
     * mb_strlen() yield `-1`.
     *
     * @param string $uncompressed Input.
     *
     * @return string|null The compressed string, or null (no return statement reached) for an
     *                     empty input.
     */
    function compress($uncompressed)
    {
        $dictSize = 256;
        $dictionary = array();
        for ($i = 0; $i < 256; $i++) {
            $dictionary[chr($i)] = $i;
        }
        $w = "";
        $result = "";
        for ($i = 0; $i < strlen($uncompressed); $i++) {
            $c = $this->charAt($uncompressed, $i);
            $wc = $w . $c;
            if (isset($dictionary[$wc])) {
                $w = $wc;
            } else {
                $result .= chr($dictionary[$w]);
                $dictionary[$wc] = $dictSize++;
                $w = "" . $c;
            }
        }
        if ($w != "") {
            $result .= chr($dictionary[$w]);
            return $result;
        }
   }
    /**
     * Decompresses a comma-separated list of LZW codes.
     *
     * @param string $compressed Codes as decimal numbers separated by commas, e.g. `"72,101,256"`.
     *
     * @return string|null The decompressed string, or null when a code is neither in the
     *                     dictionary nor the next code to be added.
     */
    function decompress($compressed)
    {
        $compressed = explode(",", $compressed);
        $dictSize = 256;
        $dictionary = array();
        for ($i = 1; $i < 256; $i++) {
            $dictionary[$i] = chr($i);
        }
        $w = chr($compressed[0]);
        $result = $w;
        for ($i = 1; $i < count($compressed); $i++) {
            $entry = "";
            $k = $compressed[$i];
            if (isset($dictionary[$k])) {
                $entry = $dictionary[$k];
            } else if ($k == $dictSize) {
                $entry = $w . $this->charAt($w, 0);
            } else {
                return null;
            }
            $result .= $entry;
            $dictionary[$dictSize++] = $w . $this->charAt($entry, 0);
            $w = $entry;
        }
        return $result;
    }

    /**
     * Returns the character at a multibyte position.
     *
     * Uses mb_strlen()/mb_substr() without an mbstring guard.
     *
     * @param string $string Input.
     * @param int    $index  Character position.
     *
     * @return string|int The character, or the int `-1` when `$index` is past the end.
     */
    function charAt($string, $index)
    {
        if ($index < mb_strlen($string)) {
            return mb_substr($string, $index, 1);
        } else {
            return -1;
        }
    }
}