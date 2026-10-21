<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

header('Content-Type: application/json');

function getRegionalNames($region, $sheet) {
    $file = __DIR__ . "/{$region}/{$sheet}.csv";
    $fp = @fopen($file, 'r');
    if ($fp === false) {
        throw new RuntimeException('Failed to read regional names');
    }

    try {
        fgetcsv($fp, 0, ',', '"', '');
        $headers = fgetcsv($fp, 0, ',', '"', '');
        $idColumn = is_array($headers) ? array_search('#', $headers, true) : false;
        $nameColumn = is_array($headers) ? array_search('Name', $headers, true) : false;
        if ($idColumn === false || $nameColumn === false) {
            throw new RuntimeException('Invalid regional name data');
        }
        fgetcsv($fp, 0, ',', '"', '');

        $names = [];
        while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) {
            if (isset($row[$idColumn], $row[$nameColumn]) && ctype_digit($row[$idColumn])) {
                $names[(int) $row[$idColumn]] = $row[$nameColumn];
            }
        }
        return $names;
    } finally {
        fclose($fp);
    }
}

function requestXivApi($path, $parameters) {
    $url = 'https://v2.xivapi.com/api/' . $path . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    $context = stream_context_create(['http' => [
        'timeout' => 15,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\n",
    ]]);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (http_get_last_response_headers() ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int) $match[1];
        }
    }
    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Failed to fetch data from XIVAPI');
    }

    $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || isset($data['error']) || isset($data['Error'])
        || !is_string($data['version'] ?? null) || !is_string($data['schema'] ?? null)) {
        throw new RuntimeException('Invalid response from XIVAPI');
    }
    return $data;
}

function normalizeResult($row, $sheet, $cn, $kr, $lang) {
    $fields = $row['fields'] ?? null;
    if (!is_int($row['row_id'] ?? null) || !is_array($fields)
        || !is_string($fields['Name'] ?? null) || !is_string($fields['Icon']['path'] ?? null)) {
        throw new RuntimeException('Invalid row from XIVAPI');
    }

    $id = $row['row_id'];
    if (!preg_match('~^ui/icon/(\d{6})/(\d{6})\.tex$~', $fields['Icon']['path'], $icon)) {
        throw new RuntimeException('Invalid icon from XIVAPI');
    }
    $result = [
        'ID' => $id,
        'Icon' => "/i/{$icon[1]}/{$icon[2]}.png",
        'Name' => $fields['Name'],
    ];
    foreach (['en', 'de', 'fr', 'ja'] as $language) {
        $name = $fields["Name@lang({$language})"] ?? null;
        if (!is_string($name)) {
            throw new RuntimeException('Missing translated name from XIVAPI');
        }
        $result["Name_{$language}"] = $name;
    }
    $result['Name_cn'] = $cn[$id] ?? 'Not found';
    $result['Name_kr'] = $kr[$id] ?? 'Not found';
    if ($lang === 'cn' || $lang === 'kr') {
        $result['Name'] = $result["Name_{$lang}"];
    }

    if ($sheet === 'Action') {
        foreach (['Recast100ms', 'ClassJobLevel', 'IsPvP', 'IsPlayerAction'] as $field) {
            $isFlag = in_array($field, ['IsPvP', 'IsPlayerAction'], true);
            if ($isFlag ? !is_bool($fields[$field] ?? null) : !is_int($fields[$field] ?? null)) {
                throw new RuntimeException('Invalid action metadata from XIVAPI');
            }
            $result[$field] = $fields[$field];
        }
        $result['ClassJob'] = ['Abbreviation' => $fields['ClassJob']['fields']['Abbreviation'] ?? null];
        $result['Description'] = $row['transient']['Description'] ?? null;
    } else {
        $result['Description'] = $fields['Description'] ?? null;
    }
    return $result;
}

function findResults($name, $sheet, $lang, $cn, $kr) {
    $fields = 'Name,Name@lang(en),Name@lang(de),Name@lang(fr),Name@lang(ja),Icon';
    $fields .= $sheet === 'Action'
        ? ',Recast100ms,ClassJob.Abbreviation,ClassJobLevel,IsPvP,IsPlayerAction'
        : ',Description';
    $parameters = ['fields' => $fields, 'transient' => $sheet === 'Action' ? 'Description' : '', 'limit' => 100];
    $results = [];

    if ($lang === 'cn' || $lang === 'kr') {
        $names = $lang === 'cn' ? $cn : $kr;
        $ids = array_keys(array_filter($names, fn($value) => stripos($value, $name) !== false));
        foreach (array_chunk($ids, 100) as $batch) {
            $data = requestXivApi('sheet/' . $sheet, $parameters + ['rows' => implode(',', $batch)]);
            if (!is_array($data['rows'] ?? null)) {
                throw new RuntimeException('Invalid row list from XIVAPI');
            }
            $parameters['version'] = $data['version'];
            $parameters['schema'] = $data['schema'];
            foreach ($data['rows'] as $row) {
                if (!in_array($row['row_id'] ?? null, $batch, true)) {
                    throw new RuntimeException('Unexpected row from XIVAPI');
                }
                $results[$row['row_id']] = normalizeResult($row, $sheet, $cn, $kr, $lang);
            }
        }
    } else {
        $parameters['sheets'] = $sheet;
        $parameters['query'] = 'Name@' . $lang . '~' . json_encode($name, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $cursors = [];
        do {
            $data = requestXivApi('search', $parameters);
            if (!is_array($data['results'] ?? null)) {
                throw new RuntimeException('Invalid search results from XIVAPI');
            }
            $parameters['version'] = $data['version'];
            $parameters['schema'] = $data['schema'];
            foreach ($data['results'] as $row) {
                $result = normalizeResult($row, $sheet, $cn, $kr, $lang);
                $results[$result['ID']] = $result;
            }
            $cursor = $data['next'] ?? null;
            if ($cursor !== null && (!is_string($cursor) || $cursor === '' || isset($cursors[$cursor]))) {
                throw new RuntimeException('Invalid search cursor from XIVAPI');
            }
            if ($cursor !== null) {
                $cursors[$cursor] = true;
                $parameters['cursor'] = $cursor;
            }
        } while ($cursor !== null);
    }

    ksort($results, SORT_NUMERIC);
    return array_values($results);
}

try {
    if (!isset($_GET['name'], $_GET['type'], $_GET['lang'])) {
        echo json_encode(['error' => 'No parameters given']);
        exit;
    }
    if (!is_string($_GET['type']) || !in_array($_GET['type'], ['action', 'status'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid type specified']);
        exit;
    }
    if (!is_string($_GET['lang']) || !in_array($_GET['lang'], ['en', 'fr', 'de', 'ja', 'cn', 'kr'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid language specified']);
        exit;
    }
    if (!is_string($_GET['name']) || trim($_GET['name']) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid name specified']);
        exit;
    }

    $sheet = ucfirst($_GET['type']);
    $cn = getRegionalNames('cn', $sheet);
    $kr = getRegionalNames('kr', $sheet);
    $results = findResults(trim($_GET['name']), $sheet, $_GET['lang'], $cn, $kr);
    echo json_encode($results ?: ['error' => 'No matching results found'], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to fetch data from XIVAPI']);
}
