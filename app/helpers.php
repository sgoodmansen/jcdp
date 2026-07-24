<?php

declare(strict_types=1);

function app_name(): string
{
    global $config;
    return $config['app']['name'];
}

function url(string $path = ''): string
{
    global $config;
    $base = rtrim($config['app']['base_url'], '/');
    return $base . '/' . ltrim($path, '/');
}

function redirect_to(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $value = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $value;
}

function normalize_vin(?string $value): string
{
    return strtoupper(trim(preg_replace('/\s+/', '', $value ?? '')));
}

function title_case_name(?string $value): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value ?? ''));

    if ($value === '') {
        return '';
    }

    return preg_replace_callback(
        '/[A-Za-z]+/',
        fn($match) => ucfirst(strtolower($match[0])),
        $value
    );
}

function title_case_company(?string $value): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value ?? ''));

    if ($value === '') {
        return '';
    }

    $alwaysUppercase = [
        'ACH',
        'AG',
        'BMW',
        'CCU',
        'CU',
        'CUDL',
        'DBA',
        'FCU',
        'FNB',
        'FSB',
        'GM',
        'GMC',
        'ID',
        'KIA',
        'LLC',
        'LLP',
        'LP',
        'NA',
        'PNC',
        'TD',
        'US',
        'USA',
        'USB',
        'VW',
    ];
    $smallWords = ['a', 'an', 'and', 'at', 'by', 'for', 'from', 'in', 'of', 'on', 'or', 'the', 'to'];
    $wordNumber = 0;

    return preg_replace_callback(
        '/[A-Za-z]+(?:\.[A-Za-z]+\.?)?/',
        function ($match) use ($alwaysUppercase, $smallWords, &$wordNumber) {
            $word = $match[0];
            $position = $wordNumber++;
            $lookup = strtoupper(str_replace('.', '', $word));

            if (in_array($lookup, $alwaysUppercase, true)) {
                return $lookup === 'NA' && str_contains($word, '.') ? 'N.A.' : $lookup;
            }

            $lowercase = strtolower($word);
            if ($position > 0 && in_array($lowercase, $smallWords, true)) {
                return $lowercase;
            }

            return ucfirst($lowercase);
        },
        $value
    );
}

function title_case_address(?string $value): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value ?? ''));

    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\bP\.?\s*O\.?\s+BOX\b/i', 'PO Box', $value);
    $value = preg_replace_callback(
        '/\b(\d+)(st|nd|rd|th)\b/i',
        fn($match) => $match[1] . strtolower($match[2]),
        $value
    );

    $addressWords = [
        'APT' => 'Apt',
        'AVE' => 'Ave',
        'AVENUE' => 'Avenue',
        'BLDG' => 'Bldg',
        'BLVD' => 'Blvd',
        'BOULEVARD' => 'Boulevard',
        'BOX' => 'Box',
        'CIR' => 'Cir',
        'CIRCLE' => 'Circle',
        'CT' => 'Ct',
        'COURT' => 'Court',
        'DEPT' => 'Dept',
        'DR' => 'Dr',
        'DRIVE' => 'Drive',
        'E' => 'E',
        'FL' => 'Fl',
        'FLOOR' => 'Floor',
        'HWY' => 'Hwy',
        'HIGHWAY' => 'Highway',
        'LN' => 'Ln',
        'LANE' => 'Lane',
        'N' => 'N',
        'NE' => 'NE',
        'NW' => 'NW',
        'PARKWAY' => 'Parkway',
        'PKWY' => 'Pkwy',
        'PO' => 'PO',
        'RD' => 'Rd',
        'ROAD' => 'Road',
        'S' => 'S',
        'SE' => 'SE',
        'STE' => 'Ste',
        'ST' => 'St',
        'STREET' => 'Street',
        'SUITE' => 'Suite',
        'SW' => 'SW',
        'UNIT' => 'Unit',
        'W' => 'W',
    ];
    $smallWords = ['and', 'at', 'by', 'for', 'from', 'in', 'of', 'on', 'or', 'the', 'to'];
    $wordNumber = 0;

    $formatted = preg_replace_callback(
        '/[A-Za-z]+/',
        function ($match) use ($addressWords, $smallWords, &$wordNumber) {
            $word = $match[0];
            $position = $wordNumber++;
            $lookup = strtoupper($word);

            if (isset($addressWords[$lookup])) {
                return $addressWords[$lookup];
            }

            $lowercase = strtolower($word);
            if ($position > 0 && in_array($lowercase, $smallWords, true)) {
                return $lowercase;
            }

            return ucfirst($lowercase);
        },
        $value
    );

    return preg_replace_callback(
        '/\b(\d+)(st|nd|rd|th)\b/i',
        fn($match) => $match[1] . strtolower($match[2]),
        $formatted
    );
}

function vin_warning(?string $value): ?string
{
    $vin = normalize_vin($value);

    if ($vin === '') {
        return null;
    }

    if (strlen($vin) !== 17) {
        return 'Standard VINs are 17 characters. Older, homemade, off-road, or state-assigned IDs may be different.';
    }

    if (preg_match('/[IOQ]/', $vin)) {
        return 'Standard VINs do not use the letters I, O, or Q.';
    }

    if (!preg_match('/^[A-Z0-9]+$/', $vin)) {
        return 'Standard VINs use only letters and numbers.';
    }

    return null;
}

function state_options(?string $selected = null): void
{
    $states = [
        'AL' => 'Alabama',
        'AK' => 'Alaska',
        'AZ' => 'Arizona',
        'AR' => 'Arkansas',
        'CA' => 'California',
        'CO' => 'Colorado',
        'CT' => 'Connecticut',
        'DE' => 'Delaware',
        'FL' => 'Florida',
        'GA' => 'Georgia',
        'HI' => 'Hawaii',
        'ID' => 'Idaho',
        'IL' => 'Illinois',
        'IN' => 'Indiana',
        'IA' => 'Iowa',
        'KS' => 'Kansas',
        'KY' => 'Kentucky',
        'LA' => 'Louisiana',
        'ME' => 'Maine',
        'MD' => 'Maryland',
        'MA' => 'Massachusetts',
        'MI' => 'Michigan',
        'MN' => 'Minnesota',
        'MS' => 'Mississippi',
        'MO' => 'Missouri',
        'MT' => 'Montana',
        'NE' => 'Nebraska',
        'NV' => 'Nevada',
        'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',
        'NM' => 'New Mexico',
        'NY' => 'New York',
        'NC' => 'North Carolina',
        'ND' => 'North Dakota',
        'OH' => 'Ohio',
        'OK' => 'Oklahoma',
        'OR' => 'Oregon',
        'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',
        'SC' => 'South Carolina',
        'SD' => 'South Dakota',
        'TN' => 'Tennessee',
        'TX' => 'Texas',
        'UT' => 'Utah',
        'VT' => 'Vermont',
        'VA' => 'Virginia',
        'WA' => 'Washington',
        'WV' => 'West Virginia',
        'WI' => 'Wisconsin',
        'WY' => 'Wyoming',
        'DC' => 'District of Columbia',
    ];

    echo '<option value="">Select state</option>';
    foreach ($states as $abbreviation => $name) {
        $isSelected = $selected === $abbreviation ? ' selected' : '';
        echo '<option value="' . e($abbreviation) . '"' . $isSelected . '>' . e($abbreviation . ' - ' . $name) . '</option>';
    }
}
