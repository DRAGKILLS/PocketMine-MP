<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\SingletonTrait;
use function array_key_exists;
use function file_get_contents;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;

final class ItemTypeDictionary{
	use SingletonTrait;

	/** @var ItemTypeDictionaryEntry[] */
	private $entries = [];

	public function __construct(){
		$this->setup(ProtocolInfo::CURRENT_PROTOCOL);
		$this->setup(ProtocolInfo::BEDROCK_1_17_30);
	}

	private function setup(int $protocol)
	{
		$data = file_get_contents(\pocketmine\RESOURCE_PATH . "/vanilla/required_item_list_" . $protocol . ".json");
		if($data === false) throw new AssumptionFailedError("Missing required resource file");
		$table = json_decode($data, true);
		if(!is_array($table)){
			throw new AssumptionFailedError("Invalid item list format");
		}

		$params = [];
		foreach($table as $name => $entry){
			if(!is_array($entry) || !is_string($name) || !isset($entry["component_based"], $entry["runtime_id"]) || !is_bool($entry["component_based"]) || !is_int($entry["runtime_id"])){
				throw new AssumptionFailedError("Invalid item list format");
			}
			$params[] = new ItemTypeEntry($name, $entry["runtime_id"], $entry["component_based"]);
		}
		$this->entries[$protocol] = new ItemTypeDictionaryEntry($params);
	}

	public function getDictionary(int $protocol = ProtocolInfo::CURRENT_PROTOCOL): ItemTypeDictionaryEntry
	{
		return $this->entries[$protocol] ?? $this->entries[ProtocolInfo::CURRENT_PROTOCOL];
	}
}
