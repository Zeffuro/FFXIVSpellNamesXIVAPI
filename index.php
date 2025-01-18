<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

ini_set('memory_limit', '-1');

function getCsv($file) {
    if (!($fp = fopen($file, 'r'))) {
        die("Can't open file...");
    }
    
    fgetcsv($fp); // Skip the first line
    $key = fgetcsv($fp, 1024, ",");
    fgetcsv($fp); // Skip the third line
    
    $array = [];
    while ($row = fgetcsv($fp, 1024, ",")) {
        $array[] = array_combine($key, $row);
    }
    
    fclose($fp);
    return $array;
}

function getJsonData($type) {
    $file = "json/{$type}.json";
    if (!file_exists($file)) {
        die("JSON file not found: $file");
    }
    
    $json_data = json_decode(file_get_contents($file), true);
    return $json_data;
}

function searchInJson($data, $name, $lang) {
    $results = [];
    foreach ($data as $item) {
        $name_field = $item["Name"] ?? '';
        if (stripos($name_field, $name) !== false) {
            $results[] = $item;
        }
    }
    return $results;
}

function fetchXivApiData($ids, $type) {
    $found_id_string = implode(",", $ids);
    $xivapi = "https://xivapi.com/{$type}?ids={$found_id_string}&Columns=ID,Icon,Name,Name_en,Name_de,Name_fr,Name_ja,Recast100ms,ClassJob.Abbreviation,ClassJobLevel,IsPvP,IsPlayerAction,Description";
    
    $response = file_get_contents($xivapi);
    if ($response === false) {
        die("Failed to fetch data from XIVAPI");
    }
    
    $xivapi_data = json_decode($response);
    return $xivapi_data->Results ?? [];
}

function enrichResults($results, $cn, $kr) {
    // Convert results from an array of stdClass objects to an array of arrays
    $resultsArray = array_map(function($result) {
        return (array) $result;
    }, $results);

    // Enrich each result with additional fields
    foreach ($resultsArray as &$result) {
        $id = $result["ID"] ?? '';
        $result["Name_cn"] = $cn[$id]["Name"] ?? "Not found";
        $result["Name_kr"] = $kr[$id]["Name"] ?? "Not found";
    }
    
    // Convert results back to an array of stdClass objects if needed
    $resultsEnriched = array_map(function($result) {
        return (object) $result;
    }, $resultsArray);

    return $resultsEnriched;
}

if (isset($_GET["name"]) && isset($_GET["type"]) && isset($_GET["lang"])) {
    header("Content-Type: application/json");
    $identifier = $_GET["name"];
    $type = $_GET["type"];
    $lang = $_GET["lang"];
    
    switch ($type) {
        case "action":
            $json_data = getJsonData("Action");
            $cn = getCsv("cn/Action.csv");
            $kr = getCsv("kr/Action.csv");
            break;
        case "status":
            $json_data = getJsonData("Status");
            $cn = getCsv("cn/Status.csv");
            $kr = getCsv("kr/Status.csv");
            break;
        default:
            die("Invalid type specified");
    }

    if (in_array($lang, ["en", "fr", "de", "ja"])) {
        // Search in the local JSON data
        $results = searchInJson($json_data, $identifier, $lang);

        if (!empty($results)) {
            $ids = array_column($results, "ID");
            $results = fetchXivApiData($ids, $type);
            $results = enrichResults($results, $cn, $kr);
            echo json_encode($results);
        } else {
            echo json_encode(["error" => "No matching results found"]);
        }
    } else {
        // For Chinese or Korean, use the cafemaker API
        $found_cn = array_filter($cn, fn($spell) => stripos($spell["Name"], $identifier) !== false);
        $found_kr = array_filter($kr, fn($spell) => stripos($spell["Name"], $identifier) !== false);
        
        $found_cn_keys = array_keys($found_cn);
        $found_kr_keys = array_keys($found_kr);
        
        $found_ids = array_unique(array_merge($found_cn_keys, $found_kr_keys));
        $found_id_string = implode(",", $found_ids);
        
        if ($lang == "cn") {
            $xivapi = "https://cafemaker.wakingsands.com/{$type}?ids={$found_id_string}&Columns=ID,Icon,Name,Name_en,Name_de,Name_fr,Name_ja,Recast100ms,ClassJob.Abbreviation,ClassJobLevel,IsPvP,IsPlayerAction,Description";
        }else{
            $xivapi = "https://xivapi.com/{$type}?ids={$found_id_string}&Columns=ID,Icon,Name,Name_en,Name_de,Name_fr,Name_ja,Recast100ms,ClassJob.Abbreviation,ClassJobLevel,IsPvP,IsPlayerAction,Description";
        }
        
        $xivapi_data = json_decode(file_get_contents($xivapi));
        
        $results = $xivapi_data->Results ?? [];
        $results = enrichResults($results, $cn, $kr);
        echo json_encode($results);
    }
} else {
    header("Content-Type: application/json");
    echo json_encode(["error" => "No parameters given"]);
}
?>