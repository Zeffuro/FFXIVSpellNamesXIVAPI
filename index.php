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

function fetchXivApi($identifier, $type, $lang) {
    if ($lang == "jp") {
        $lang = "ja";
    }
    $xivapi = "https://xivapi.com/search?string={$identifier}&indexes={$type}&Columns=ID,Icon,Name,Name_en,Name_de,Name_fr,Name_ja,UrlType,Recast100ms,ClassJob.Abbreviation,ClassJobLevel,IsPvP,IsPlayerAction,Description";
    
    $xivapi_data = json_decode(file_get_contents($xivapi));
    
    return $xivapi_data->Results ?? [];
}

function enrichResults($results, $cn, $kr) {
    foreach ($results as $result) {
        $result->Name_cn = $cn[$result->ID]["Name"] ?? "Not found";
        $result->Name_kr = $kr[$result->ID]["Name"] ?? "Not found";
        $result->Cooldown = $result->Recast100ms / 10;
        
        $duration_regex = "/(?:Duration:|持续时间：)<\/span>(?: )?(\d+)(?:s|秒)/";
        $duration = 0;
        preg_match($duration_regex, $result->Description, $matches);
        if (count($matches) > 0) {
            $duration = $matches[1];
        }
        $result->Duration = intval($duration);
        
        $charges_regex = "/(?:Maximum Charges: |积蓄次数：)<\/span>(?: )?(\d+)/";
        $charges = 0;
        preg_match($charges_regex, $result->Description, $matches);
        if (count($matches) > 0) {
            $charges = $matches[1];
        }
        $result->Charges = intval($charges);
    }
    return $results;
}

if (isset($_GET["id"]) || (isset($_GET["name"]) && isset($_GET["type"]) && isset($_GET["lang"]))) {
    $method = isset($_GET["id"]) ? "id" : "name";
    $identifier = $method === "id" ? $_GET["id"] : $_GET["name"];
    $type = $_GET["type"];
    $lang = $_GET["lang"];
    
    switch ($type) {
        case "action":
            $cn = getCsv("cn/Action.csv");
            $kr = getCsv("kr/Action.csv");
            break;
        case "status":
            $cn = getCsv("cn/Status.csv");
            $kr = getCsv("kr/Status.csv");
            break;
        default:
            die("Invalid type specified");
    }

    if ($method === "id") {
        $spelldata = [
            "id" => $identifier,
            "type" => ucfirst($type),
            "cn" => $cn[$identifier]["Name"] ?? "Not found",
            "kr" => $kr[$identifier]["Name"] ?? "Not found"
        ];
        echo json_encode($spelldata);
    } else {
        $results = [];
        if (in_array($lang, ["en", "fr", "de", "ja"])) {
            // Query XIVAPI first
            $results = fetchXivApi($identifier, $type, $lang);
        } else {
            // Directly filter CSV files for Chinese and Korean
            $found_cn = array_filter($cn, fn($spell) => stripos($spell["Name"], $identifier) !== false);
            $found_kr = array_filter($kr, fn($spell) => stripos($spell["Name"], $identifier) !== false);
            
            $found_cn_keys = array_keys($found_cn);
            $found_kr_keys = array_keys($found_kr);
            
            $found_ids = array_unique(array_merge($found_cn_keys, $found_kr_keys));
            $found_id_string = implode(",", $found_ids);
            
            if ($lang == "cn") {
                $xivapi = "https://cafemaker.wakingsands.com/{$type}?ids={$found_id_string}&Columns=ID,Icon,Name,Name_en,Name_de,Name_fr,Name_ja,Recast100ms,ClassJob.Abbreviation,ClassJobLevel,IsPvP,IsPlayerAction,Description";
            } else {
                $xivapi = "https://xivapi.com/{$type}?ids={$found_id_string}&Columns=ID,Icon,Name,Name_en,Name_de,Name_fr,Name_ja,Recast100ms,ClassJob.Abbreviation,ClassJobLevel,IsPvP,IsPlayerAction,Description";
            }
            
            $xivapi_data = json_decode(file_get_contents($xivapi));
            
            $results = $xivapi_data->Results ?? [];
        }

        // Enrich results with CN and KR names
        $results = enrichResults($results, $cn, $kr);
        
        header("Content-Type: application/json");
        echo json_encode($results);
    }
} else {
    header("Content-Type: application/json");
    echo json_encode(["error" => "No parameters given"]);
}
?>
