<?php
/**
 * Helper utility functions for PHP application.
 */

function parse_accounting_month($value) {
    $value = trim($value);
    if (!preg_match('/^(0[1-9]|1[0-2])\/(20\d{2})$/', $value, $matches)) {
        throw new Exception("Accounting month must use MM/YYYY, for example 07/2026.");
    }
    return [(int)$matches[1], (int)$matches[2]];
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
    $text = trim($value);
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $text, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
        return $text;
    }
    return null;
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
    if (preg_match('/^TR(\d+)$/i', $code, $m)) {
        return "TR-" . $m[1];
    }
    return $code;
}

function controller_to_ministry($controller) {
    if (empty($controller)) {
        return "Ministry / Department";
    }
    $parts = explode(" - ", $controller, 2);
    $raw_name = trim(end($parts));
    if (preg_match('/^ministry\s+of\b/i', $raw_name)) {
        return $raw_name;
    }
    return "Ministry of " . $raw_name;
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
