<?php
/**
 * Native Pure PHP BIFF8 Binary Excel (.xls) file parser.
 * Reads OLE2 compound document container and BIFF8 workbook streams.
 */

class SimpleXlsReader {
    /**
     * Parse BIFF8 .xls file into array of rows.
     *
     * @param string $filePath
     * @return array
     */
    public static function parseFile(string $filePath): array {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [];
        }

        $content = @file_get_contents($filePath);
        if (!$content || strlen($content) < 512) {
            return [];
        }

        // Check OLE signature: D0 CF 11 E0 A1 B1 1A E1
        if (substr($content, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            return [];
        }

        $workbookStream = self::extractWorkbookStream($content);
        if (!$workbookStream) {
            return [];
        }

        return self::parseBiffStream($workbookStream);
    }

    /**
     * Extract Workbook stream from OLE2 container.
     */
    private static function extractWorkbookStream(string $data): ?string {
        $sectorSizeShift = unpack('v', substr($data, 0x1E, 2))[1];
        $sectorSize = 1 << $sectorSizeShift;

        $numSatSectors = unpack('V', substr($data, 0x2C, 4))[1];
        $dirFirstSector = unpack('V', substr($data, 0x30, 4))[1];
        $miniStreamFirstSector = unpack('V', substr($data, 0x3C, 4))[1];
        $numMiniSatSectors = unpack('V', substr($data, 0x40, 4))[1];

        // Read Master SAT (MSAT)
        $satSectors = [];
        for ($i = 0; $i < 109 && count($satSectors) < $numSatSectors; $i++) {
            $secId = unpack('V', substr($data, 0x4C + $i * 4, 4))[1];
            if ($secId != 0xFFFFFFFF && $secId != 0xFFFFFFFE) {
                $satSectors[] = $secId;
            }
        }

        // Additional MSAT sectors if > 109
        $msatFirstSector = unpack('V', substr($data, 0x44, 4))[1];
        $currMsat = $msatFirstSector;
        while ($currMsat != 0xFFFFFFFF && $currMsat != 0xFFFFFFFE && count($satSectors) < $numSatSectors) {
            $offset = ($currMsat + 1) * $sectorSize;
            for ($i = 0; $i < ($sectorSize / 4 - 1) && count($satSectors) < $numSatSectors; $i++) {
                $secId = unpack('V', substr($data, $offset + $i * 4, 4))[1];
                if ($secId != 0xFFFFFFFF && $secId != 0xFFFFFFFE) {
                    $satSectors[] = $secId;
                }
            }
            $currMsat = unpack('V', substr($data, $offset + $sectorSize - 4, 4))[1];
        }

        // Build FAT
        $fat = [];
        foreach ($satSectors as $secId) {
            $offset = ($secId + 1) * $sectorSize;
            for ($i = 0; $i < $sectorSize / 4; $i++) {
                $fat[] = unpack('V', substr($data, $offset + $i * 4, 4))[1];
            }
        }

        // Read sector chain helper
        $readChain = function($firstSecId) use ($data, $sectorSize, $fat) {
            $out = '';
            $curr = $firstSecId;
            $visited = [];
            while ($curr != 0xFFFFFFFF && $curr != 0xFFFFFFFE && $curr >= 0 && !isset($visited[$curr])) {
                $visited[$curr] = true;
                $offset = ($curr + 1) * $sectorSize;
                $out .= substr($data, $offset, $sectorSize);
                $curr = $fat[$curr] ?? 0xFFFFFFFE;
            }
            return $out;
        };

        // Directory Stream
        $dirData = $readChain($dirFirstSector);
        if (!$dirData) return null;

        $numDirEntries = strlen($dirData) / 128;
        $workbookEntry = null;
        $rootEntry = null;

        for ($i = 0; $i < $numDirEntries; $i++) {
            $entry = substr($dirData, $i * 128, 128);
            $nameLen = unpack('v', substr($entry, 0x40, 2))[1];
            if ($nameLen <= 0) continue;

            $rawName = substr($entry, 0, $nameLen - 2);
            $name = mb_convert_encoding($rawName, 'UTF-8', 'UTF-16LE');
            $type = ord($entry[0x42]);
            $startSec = unpack('V', substr($entry, 0x74, 4))[1];
            $streamSize = unpack('V', substr($entry, 0x78, 4))[1];

            if ($type == 5) { // Root Entry
                $rootEntry = ['startSec' => $startSec, 'size' => $streamSize];
            } elseif (strcasecmp($name, 'Workbook') === 0 || strcasecmp($name, 'Book') === 0) {
                $workbookEntry = ['startSec' => $startSec, 'size' => $streamSize];
            }
        }

        if (!$workbookEntry) return null;

        // Check if stream is in Mini Stream (< 4096 bytes)
        if ($workbookEntry['size'] < 4096 && $rootEntry) {
            $miniSatData = $readChain($numMiniSatSectors);
            $miniFat = [];
            for ($i = 0; $i < strlen($miniSatData) / 4; $i++) {
                $miniFat[] = unpack('V', substr($miniSatData, $i * 4, 4))[1];
            }

            $miniStreamData = $readChain($rootEntry['startSec']);
            $out = '';
            $curr = $workbookEntry['startSec'];
            $visited = [];
            while ($curr != 0xFFFFFFFF && $curr != 0xFFFFFFFE && $curr >= 0 && !isset($visited[$curr])) {
                $visited[$curr] = true;
                $out .= substr($miniStreamData, $curr * 64, 64);
                $curr = $miniFat[$curr] ?? 0xFFFFFFFE;
            }
            return substr($out, 0, $workbookEntry['size']);
        } else {
            $streamData = $readChain($workbookEntry['startSec']);
            return substr($streamData, 0, $workbookEntry['size']);
        }
    }

    /**
     * Parse BIFF8 stream records into structured rows.
     */
    private static function parseBiffStream(string $data): array {
        $pos = 0;
        $len = strlen($data);
        $sharedStrings = [];
        $cellValues = [];

        while ($pos < $len) {
            if ($pos + 4 > $len) break;
            $code = unpack('v', substr($data, $pos, 2))[1];
            $recLen = unpack('v', substr($data, $pos + 2, 2))[1];
            $pos += 4;

            if ($pos + $recLen > $len) break;
            $recData = substr($data, $pos, $recLen);
            $pos += $recLen;

            // SST - Shared String Table (0x00FC)
            if ($code === 0x00FC) {
                $totalStrings = unpack('V', substr($recData, 0, 4))[1];
                $uniqueStrings = unpack('V', substr($recData, 4, 4))[1];

                // Gather CONTINUE records for SST
                $extraData = '';
                $tempPos = $pos;
                while ($tempPos < $len) {
                    $nextCode = unpack('v', substr($data, $tempPos, 2))[1];
                    if ($nextCode !== 0x003C) break; // CONTINUE
                    $nextLen = unpack('v', substr($data, $tempPos + 2, 2))[1];
                    $extraData .= substr($data, $tempPos + 4, $nextLen);
                    $tempPos += 4 + $nextLen;
                }
                $pos = $tempPos;

                $fullSstData = substr($recData, 8) . $extraData;
                $sstPos = 0;
                $sstLen = strlen($fullSstData);

                for ($s = 0; $s < $uniqueStrings && $sstPos < $sstLen; $s++) {
                    if ($sstPos + 2 > $sstLen) break;
                    $numChars = unpack('v', substr($fullSstData, $sstPos, 2))[1];
                    $sstPos += 2;

                    if ($sstPos >= $sstLen) break;
                    $optionFlags = ord($fullSstData[$sstPos]);
                    $sstPos += 1;

                    $isCompressed = (($optionFlags & 0x01) === 0);
                    $hasAsianPhonetic = (($optionFlags & 0x04) !== 0);
                    $hasRichText = (($optionFlags & 0x08) !== 0);

                    $rtRuns = 0;
                    if ($hasRichText) {
                        if ($sstPos + 2 > $sstLen) break;
                        $rtRuns = unpack('v', substr($fullSstData, $sstPos, 2))[1];
                        $sstPos += 2;
                    }

                    $szPhonetic = 0;
                    if ($hasAsianPhonetic) {
                        if ($sstPos + 4 > $sstLen) break;
                        $szPhonetic = unpack('V', substr($fullSstData, $sstPos, 4))[1];
                        $sstPos += 4;
                    }

                    $strBytes = $numChars * ($isCompressed ? 1 : 2);
                    if ($sstPos + $strBytes > $sstLen) {
                        $strBytes = $sstLen - $sstPos;
                    }
                    $rawStr = substr($fullSstData, $sstPos, $strBytes);
                    $sstPos += $strBytes;

                    if ($isCompressed) {
                        $str = $rawStr;
                    } else {
                        $str = mb_convert_encoding($rawStr, 'UTF-8', 'UTF-16LE');
                    }

                    $sstPos += $rtRuns * 4 + $szPhonetic;
                    $sharedStrings[] = trim($str);
                }
            }
            // LABELSST (0x00FD)
            elseif ($code === 0x00FD) {
                $row = unpack('v', substr($recData, 0, 2))[1];
                $col = unpack('v', substr($recData, 2, 2))[1];
                $sstIdx = unpack('V', substr($recData, 6, 4))[1];

                $val = $sharedStrings[$sstIdx] ?? '';
                $cellValues[$row][$col] = $val;
            }
            // LABEL (0x0204)
            elseif ($code === 0x0204) {
                $row = unpack('v', substr($recData, 0, 2))[1];
                $col = unpack('v', substr($recData, 2, 2))[1];
                $numChars = unpack('v', substr($recData, 6, 2))[1];
                $flags = ord($recData[8]);
                $rawStr = substr($recData, 9, $numChars * (($flags & 0x01) ? 2 : 1));
                $val = ($flags & 0x01) ? mb_convert_encoding($rawStr, 'UTF-8', 'UTF-16LE') : $rawStr;
                $cellValues[$row][$col] = trim($val);
            }
            // NUMBER (0x0203)
            elseif ($code === 0x0203) {
                $row = unpack('v', substr($recData, 0, 2))[1];
                $col = unpack('v', substr($recData, 2, 2))[1];
                $doubleVal = unpack('d', substr($recData, 6, 8))[1];
                $cellValues[$row][$col] = (string)$doubleVal;
            }
            // RK (0x027E)
            elseif ($code === 0x027E) {
                $row = unpack('v', substr($recData, 0, 2))[1];
                $col = unpack('v', substr($recData, 2, 2))[1];
                $rk = unpack('V', substr($recData, 6, 4))[1];
                $cellValues[$row][$col] = (string)self::decodeRk($rk);
            }
            // MULRK (0x00BD)
            elseif ($code === 0x00BD) {
                $row = unpack('v', substr($recData, 0, 2))[1];
                $firstCol = unpack('v', substr($recData, 2, 2))[1];
                $numRks = ($recLen - 6) / 6;
                for ($i = 0; $i < $numRks; $i++) {
                    $rk = unpack('V', substr($recData, 6 + $i * 6 + 2, 4))[1];
                    $cellValues[$row][$firstCol + $i] = (string)self::decodeRk($rk);
                }
            }
        }

        if (empty($cellValues)) return [];
        ksort($cellValues);
        $rows = [];
        foreach ($cellValues as $r => $cols) {
            ksort($cols);
            $maxCol = max(array_keys($cols));
            $rowArr = [];
            for ($c = 0; $c <= $maxCol; $c++) {
                $rowArr[] = $cols[$c] ?? '';
            }
            $rows[] = $rowArr;
        }

        return $rows;
    }

    /**
     * Decode BIFF RK value.
     *
     * @param int $rk
     * @return float|int
     */
    private static function decodeRk(int $rk) {
        if ($rk & 0x02) {
            $val = $rk >> 2;
        } else {
            $hex = sprintf('%08x', $rk & 0xFFFFFFFC);
            $doubleBin = hex2bin($hex) . "\x00\x00\x00\x00";
            $val = unpack('d', $doubleBin)[1] ?? 0;
        }
        if ($rk & 0x01) {
            $val /= 100;
        }
        return $val;
    }
}
