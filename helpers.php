<?php
/**
 * Helper utility functions for PHP application.
 */

function parse_accounting_month($value) {
    $value = trim($value);
    if (preg_match('/^(\d{1,2})\/(20\d{2})$/', $value, $matches)) {
        $m = (int)$matches[1];
        $y = (int)$matches[2];
        if ($m >= 1 && $m <= 12) {
            return [$m, $y];
        }
    }
    if (preg_match('/^(20\d{2})-(\d{1,2})$/', $value, $matches)) {
        $y = (int)$matches[1];
        $m = (int)$matches[2];
        if ($m >= 1 && $m <= 12) {
            return [$m, $y];
        }
    }
    throw new Exception("Accounting month must use MM/YYYY format, e.g. 04/2026.");
}

/**
 * Calculates financial accounting month code:
 * 1 for April, 2 for May, 3 for June ... 9 for December, 10 for January, 11 for February, 12 for March.
 *
 * @param string|int $value Date string, MM/YYYY string, or calendar month
 * @return int 1-12
 */
function acct_month_code($value) {
    if (empty($value)) {
        $m = (int)date('m');
    } elseif (is_numeric($value)) {
        $val_int = (int)$value;
        if ($val_int === 13) return 13;
        if ($val_int >= 1 && $val_int <= 12) {
            $m = $val_int;
        } else {
            $m = (int)date('m');
        }
    } else {
        $text = trim((string)$value);
        if (preg_match('/^13\/(20\d{2})$/', $text) || preg_match('/^(20\d{2})-13$/', $text) || preg_match('/\b(sy|supplementary)\b/i', $text)) {
            return 13;
        }
        if (preg_match('/^(\d{1,2})\/(20\d{2})$/', $text, $match)) {
            $m = (int)$match[1];
        } elseif (preg_match('/^(20\d{2})-(\d{1,2})/', $text, $match)) {
            $m = (int)$match[2];
        } elseif (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](20\d{2})/', $text, $match)) {
            $m = (int)$match[2];
        } else {
            $m = (int)date('m');
        }
    }

    if ($m === 13) return 13;
    return ($m >= 4) ? ($m - 3) : ($m + 9);
}

function financial_year($value) {
    if (empty($value)) return "2026-27";
    $text = trim($value);
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $text, $m)) {
        $month = (int)$m[2];
        $year = (int)$m[3];
    } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m)) {
        $month = (int)$m[2];
        $year = (int)$m[1];
    } elseif (preg_match('/^(\d{2})\/(\d{4})$/', $text, $m)) {
        $month = (int)$m[1];
        $year = (int)$m[2];
    } else {
        $month = (int)date('m');
        $year = (int)date('Y');
    }

    $start_year = ($month >= 4) ? $year : ($year - 1);
    $next_year_suffix = substr((string)($start_year + 1), -2);
    return "{$start_year}-{$next_year_suffix}";
}

function fin_year_code($value) {
    if (!empty($value)) {
        $text = trim((string)$value);
        if (preg_match('/^(20\d{2})/', $text, $m)) {
            return (int)$m[1] - 1998;
        }
        if (preg_match('/^(\d{1,2})\/(20\d{2})$/', $text, $m)) {
            $month = (int)$m[1];
            $year = (int)$m[2];
            $start_year = ($month >= 4) ? $year : ($year - 1);
            return $start_year - 1998;
        }
    }
    return 28; // Default for 2026-27 (2026 - 1998 = 28)
}

function format_date($value) {
    if (empty($value)) return "";
    $text = trim($value);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m)) {
        return "{$m[3]}/{$m[2]}/{$m[1]}";
    }
    return $text;
}

function to_db_date($value) {
    if (empty($value)) return null;
    $text = trim((string)$value);
    
    // DD/MM/YYYY or DD-MM-YYYY or DD.MM.YYYY
    if (preg_match('/^(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{4})$/', $text, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    // YYYY-MM-DD
    if (preg_match('/^(\d{4})[\/\.-](\d{1,2})[\/\.-](\d{1,2})$/', $text, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    // Excel Serial Number (e.g. 45150)
    if (is_numeric($text) && (float)$text > 30000 && (float)$text < 80000) {
        $days = (int)floor((float)$text);
        $dt = new DateTime('1899-12-30');
        $dt->modify("+{$days} days");
        return $dt->format('Y-m-d');
    }
    // Textual dates like 10-Aug-2026 or 10-Aug-26
    $ts = strtotime($text);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }

    return null;
}

function to_db_time($value) {
    if ($value === null || $value === '') return "00:00:00";
    $text = trim((string)$value);
    if ($text === '') return "00:00:00";

    // 1. Excel Serial Number float (e.g., 45150.37692 or 0.37692)
    if (is_numeric($text) && (float)$text >= 0) {
        $num = (float)$text;
        $frac = $num - floor($num);
        if ($frac > 0) {
            $total_sec = (int)round($frac * 86400);
            $hours = (int)floor($total_sec / 3600) % 24;
            $minutes = (int)floor(($total_sec % 3600) / 60);
            $seconds = $total_sec % 60;
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }
    }

    // 2. Datetime string containing date and time, e.g. "2026-08-06 09:02:46" or "06/08/2026 10:35:56" or "06-08-2026 09:02:46 PM"
    if (preg_match('/\d{1,4}[\/\.-]\d{1,2}[\/\.-]\d{1,4}\s+(\d{1,2}:\d{2}(?::\d{2})?(?:\s*[AP]M)?)/i', $text, $m)) {
        $ts = strtotime($m[1]);
        if ($ts !== false) {
            return date('H:i:s', $ts);
        }
        $parts = explode(':', $m[1]);
        return sprintf('%02d:%02d:%02d', (int)($parts[0]??0), (int)($parts[1]??0), (int)($parts[2]??0));
    }

    // 3. Standalone time string like "09:02:46" or "9:02 AM" or "14:11:04"
    if (preg_match('/\b(\d{1,2}:\d{2}(?::\d{2})?(?:\s*[AP]M)?)\b/i', $text, $m)) {
        $ts = strtotime($m[1]);
        if ($ts !== false) {
            return date('H:i:s', $ts);
        }
    }

    return "00:00:00";
}


function indian_number($value) {
    $val = round((float)($value ?? 0.0), 2);
    $sign = ($val < 0) ? "-" : "";
    $abs_val = abs($val);
    $int_part = (int)$abs_val;
    $dec_part = substr(sprintf("%.2f", $abs_val - $int_part), 1);

    $digits = (string)$int_part;
    if (strlen($digits) <= 3) {
        $formatted_int = $digits;
    } else {
        $last_three = substr($digits, -3);
        $remaining = substr($digits, 0, -3);
        $groups = [];
        while (strlen($remaining) > 0) {
            $groups[] = substr($remaining, -2);
            $remaining = substr($remaining, 0, -2);
        }
        $formatted_int = implode(",", array_reverse($groups)) . "," . $last_three;
    }

    return $sign . $formatted_int . $dec_part;
}

function display_tr_code($code) {
    $code = trim($code ?? '');
    if (preg_match('/^TR[\s\-_.]*(\d+)$/i', $code, $m)) {
        return sprintf("TR-%02d", (int)$m[1]);
    }
    return $code;
}

function extract_tr_code(?string $text): ?string {
    if ($text === null) return null;
    $text = trim($text);
    if ($text === '') return null;

    if (preg_match('/TR[\s\-_.]*(\d+)/i', $text, $matches)) {
        $num = (int)$matches[1];
        return sprintf('TR%02d', $num);
    }

    if (preg_match('/^0*(\d{1,2})$/', $text, $matches)) {
        $num = (int)$matches[1];
        return sprintf('TR%02d', $num);
    }

    return null;
}

function controller_to_ministry($controller) {
    if (empty($controller)) {
        return "Ministry / Department";
    }
    $parts = explode(" - ", $controller, 2);
    $raw_name = trim(end($parts));
    $clean_name = preg_replace('/^ministry\s+of\s+/i', '', $raw_name);
    return "Ministry of " . strtoupper($clean_name);
}

function format_head_code($val) {
    if ($val === null || $val === "") {
        return "00";
    }
    if (is_numeric($val)) {
        return sprintf("%02d", (int)$val);
    }
    return trim($val);
}
