<?php
ini_set('memory_limit', '-1');
header('Access-Control-Allow-Origin: *');
function getCsv($file){
    if (!($fp = fopen($file, 'r'))) {
        die("Can't open file...");
    }
    
	fgetcsv($fp);
	$key = fgetcsv($fp,"1024",",");
	fgetcsv($fp);
    
    $array = array();
        while ($row = fgetcsv($fp,"1024",",")) {
        $array[] = array_combine($key, $row);
    }
    
    fclose($fp);
    return $array;
}

if((isset($_GET["id"]) || (isset($_GET["name"])) && $_GET["type"] && $_GET["lang"])){
	if(isset($_GET["id"])){
		$method = "id";
		$id = $_GET["id"];
	}
	if(isset($_GET["name"])){
		$method = "name";
		$name = $_GET["name"];
	}
	$type = $_GET["type"];
	$lang = $_GET["lang"];
	
	switch($type){
		case "action":
			$cn = getCsv("cn/Action.csv");
			$kr = getCsv("kr/Action.csv");
			break;
		case "status":
			$cn = getCsv("cn/Status.csv");
			$kr = getCsv("kr/Status.csv");
			break;
	}

	switch($method){
		case "id":
			$spelldata = [
				"id" => $id,
				"type" => ucfirst($type),
				"cn" => $cn[$id]["Name"],
				"kr" => $kr[$id]["Name"]
			];
			echo json_encode($spelldata);
			break;
		case "name":
			$found_cn = array_filter($cn, function($spell) use($name){
				return (stripos($spell["Name"], $name) !== false);
			});
			$found_kr = array_filter($kr, function($spell) use($name){
				return (stripos($spell["Name"], $name) !== false);
			});
			$found_id_array = array_merge(array_keys($found_cn), array_keys($found_kr));

			$found_id_string = implode(",", $found_id_array);
			$usedsl = false;
			$context = null;
			if($lang == "en"){
				$xivapi = "https://xivapi.com/search?string={$name}&indexes={$type}&Columns=ID,Icon,Name,Name_en,Name_de,Name_fr,Name_ja,UrlType,Recast100ms,ClassJob.Abbreviation,ClassJobLevel,IsPvP,IsPlayerAction,Description";
			}else if($lang != "cn" && $lang != "kr"){
				if($lang == "jp"){
					$lang = "ja";
				}
				$xivapi = "https://xivapi.com/search?string={$name}&indexes={$type}&Columns=ID,Icon,Name,Name_en,Name_de,Name_fr,Name_ja,UrlType,Recast100ms,ClassJob.Abbreviation,ClassJobLevel,IsPvP,IsPlayerAction,Description";
				$usedsl = true;
				$payload = new stdClass();
				$payload->indexes = $type;
				$payload->columns = "ID,Icon,Name,Name_en,Name_de,Name_fr,Name_ja,UrlType,Recast100ms,ClassJob.Abbreviation,ClassJobLevel,IsPvP,IsPlayerAction,Description";
				$payload->body = new stdClass();
				$payload->body->query = new stdClass();
				$payload->body->query->query_string = new stdClass();
				$payload->body->query->query_string->query = "*{$name}*";				
				$payload->body->query->query_string->fields = ["Name_{$lang}"];
				$options = array("http" =>
					array(
						"method" => "POST",
						"header" => "Content-type: application/json",
						"content" => json_encode($payload)
					)
				);
				$context = stream_context_create($options);
			}else if($lang == "cn"){
				$xivapi = "https://cafemaker.wakingsands.com/{$type}?ids={$found_id_string}&Columns=ID,Icon,Name,Name_en,Name_de,Name_fr,Name_ja,Recast100ms,ClassJob.Abbreviation,ClassJobLevel,IsPvP,IsPlayerAction,Description";
			}else {
				$xivapi = "https://xivapi.com/{$type}?ids={$found_id_string}&Columns=ID,Icon,Name,Name_en,Name_de,Name_fr,Name_ja,Recast100ms,ClassJob.Abbreviation,ClassJobLevel,IsPvP,IsPlayerAction,Description";
			}
			if($usedsl){
				$xivapi_data = json_decode(file_get_contents($xivapi, false, $context));
			}else{
				$xivapi_data = json_decode(file_get_contents($xivapi));
			}
			

			$results = $xivapi_data->Results;
			foreach($results as $result){
				$result->Name_cn = $cn[$result->ID]["Name"];
				$result->Name_kr = $kr[$result->ID]["Name"];
				$result->Cooldown = $result->Recast100ms / 10;
				$duration_regex = "/(?:Duration:|持续时间：)<\/span>(?: )?(\d+)(?:s|秒)/";
				$duration = 0;
				preg_match($duration_regex, $result->Description, $matches);
				if(count($matches) > 0){
					$duration = $matches[1];
				}
				$result->Duration = intval($duration);	
				$charges_regex = "/(?:Maximum Charges: |积蓄次数：)<\/span>(?: )?(\d+)/";
				$charges = 0;
				preg_match($charges_regex, $result->Description, $matches);
				if(count($matches) > 0){
					$charges = $matches[1];
				}
				$result->Charges = intval($charges);	
			}
			header("Content-Type: application/json");
			echo json_encode($results);
			break;
	}
}else{
	echo "No parameters given";
}
?>