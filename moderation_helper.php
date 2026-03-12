<?php

function getBadWordsList() {
    return [
        'putangina',
        'puta',
        'gago',
        'bobo',
        'ulol',
        'tanga',
        'tarantado',
        'inutil',
        'shit',
        'fuck',
        'bitch',
        'asshole',
        'damn',
        'yawa',
        'whore',
        'cunt',
        'nigga',
        'nigger',
        'penis',
        'dick',
        'pussy',
        'puke'
    ];
}

/*
|--------------------------------------------------------------------------
| 1. Normalize text for detection
|--------------------------------------------------------------------------
| This makes bad-word detection stronger by:
| - converting to lowercase
| - changing common symbol/number replacements to letters
| - removing spaces/symbols between letters
| - reducing repeated letters
*/
function normalizeText($text) {
    $text = strtolower($text);

    // Replace common leetspeak / symbol substitutions
    $map = [
        '0' => 'o',
        '1' => 'i',
        '3' => 'e',
        '4' => 'a',
        '5' => 's',
        '7' => 't',
        '@' => 'a',
        '$' => 's',
        '!' => 'i'
    ];

    $text = strtr($text, $map);

    // Remove everything except letters and numbers first
    $text = preg_replace('/[^a-z0-9\s]/i', ' ', $text);

    // Reduce repeated characters: "shiiittt" -> "shiit"
    $text = preg_replace('/(.)\1{2,}/u', '$1$1', $text);

    // Remove all spaces for joined detection:
    // "s h i t" -> "shit"
    // "s-h-i-t" -> "shit"
    $joined = preg_replace('/\s+/', '', $text);

    return $joined;
}

/*
|--------------------------------------------------------------------------
| 2. Build flexible regex pattern
|--------------------------------------------------------------------------
| Example for "shit":
| s+[\W_]*h+[\W_]*i+[\W_]*t+
|
| This helps catch:
| - shittt
| - shiiit
| - s h i t
| - s-h-i-t
| - s.h.i.t
*/
function buildBadWordPattern($word) {
    $letters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
    $parts = [];

    foreach ($letters as $letter) {
        $parts[] = preg_quote($letter, '/') . '+';
    }

    return '/' . implode('[\W_]*', $parts) . '/i';
}

/*
|--------------------------------------------------------------------------
| 3. Detect bad words
|--------------------------------------------------------------------------
*/
function containsBadWords($text) {
    $badWords = getBadWordsList();

    $originalText = strtolower($text);
    $normalizedText = normalizeText($text);

    foreach ($badWords as $word) {
        // Exact word check
        $exactPattern = '/\b' . preg_quote($word, '/') . '\b/i';
        if (preg_match($exactPattern, $originalText)) {
            return true;
        }

        // Joined/normalized check
        if (strpos($normalizedText, $word) !== false) {
            return true;
        }

        // Flexible pattern check for smart spellings
        $flexPattern = buildBadWordPattern($word);
        if (preg_match($flexPattern, $originalText)) {
            return true;
        }
    }

    return false;
}

/*
|--------------------------------------------------------------------------
| 4. Filter bad words in displayed text
|--------------------------------------------------------------------------
| Replaces detected bad words with asterisks even if spaced/symbolized.
*/
function filterBadWords($text) {
    $badWords = getBadWordsList();

    foreach ($badWords as $word) {
        $replacement = str_repeat('*', strlen($word));

        // Exact word replacement
        $exactPattern = '/\b' . preg_quote($word, '/') . '\b/i';
        $text = preg_replace($exactPattern, $replacement, $text);

        // Flexible smart-spelling replacement
        $flexPattern = buildBadWordPattern($word);
        $text = preg_replace($flexPattern, $replacement, $text);
    }

    return $text;
}

?>