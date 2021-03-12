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
			//print_r(json_encode($found_id_array));
			$found_id_string = implode(",", $found_id_array);
			if($lang != "cn" && $lang != "kr"){
				$xivapi = "https://xivapi.com/search?string={$name}&indexes={$type}&Columns=ID,Icon,Name,Name_en,Name_de,Name_fr,Name_ja,UrlType,Recast100ms,ClassJob.Abbreviation,ClassJobLevel,IsPvP,IsPlayerAction,Description";
			}else{
				$xivapi = "https://xivapi.com/{$type}?ids={$found_id_string}&Columns=ID,Icon,Name,Name_en,Name_de,Name_fr,Name_ja,Recast100ms,ClassJob.Abbreviation,ClassJobLevel,IsPvP,IsPlayerAction,Description";
			}
			$xivapi_data = json_decode(file_get_contents($xivapi));

			$results = $xivapi_data->Results;
			foreach($results as $result){
				$result->Name_cn = $cn[$result->ID]["Name"];
				$result->Name_kr = $kr[$result->ID]["Name"];
				$result->Cooldown = $result->Recast100ms / 10;
				$duration_regex = "/Duration:<\/span> (\d+)s/";
				$duration = 0;
				preg_match($duration_regex, $result->Description, $matches);
				if(count($matches) > 0){
					$duration = $matches[1];
				}
				$result->Duration = intval($duration);	
				$charges_regex = "/Maximum Charges: <\/span>(\d+)/";
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