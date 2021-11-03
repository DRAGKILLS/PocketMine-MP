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

use pocketmine\block\BlockIds;
use pocketmine\nbt\NBT;
use pocketmine\nbt\NetworkLittleEndianNBTStream;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkBinaryStream;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\utils\AssumptionFailedError;
use function file_get_contents;
use function json_decode;
use const pocketmine\RESOURCE_PATH;

/**
 * @internal
 */
final class RuntimeBlockMapping{

	/** @var CompoundTag[]|null */
	private static $bedrockKnownStates = null;
	/** @var BlockMapping[] */
	private static $mappings = [];

	public const ACCEPTED_PROTOCOLS_toString = [
		ProtocolInfo::CURRENT_PROTOCOL => "471",
		ProtocolInfo::BEDROCK_1_17_30 => "465"
	];

	private function __construct(){
		//NOOP
	}

	public static function init() : void{
		self::setupPlatte(ProtocolInfo::CURRENT_PROTOCOL);

//		self::setupJsonMapping(ProtocolInfo::BEDROCK_1_17_30);

		self::$mappings[ProtocolInfo::CURRENT_PROTOCOL] = self::setupLegacyMappings(ProtocolInfo::CURRENT_PROTOCOL);
	}

	private static function lazyInit() : void{
		if(self::$bedrockKnownStates === null){
			self::init();
		}
	}

	private static function setupPlatte(int $protocol)
	{
		if(!in_array($protocol, ProtocolInfo::ACCEPTED_PROTOCOLS)){
			throw new AssumptionFailedError("Invalid Protocol {$protocol}");
		}
		$canonicalBlockStatesFile = file_get_contents(RESOURCE_PATH . "vanilla/canonical_block_states_" . self::ACCEPTED_PROTOCOLS_toString[$protocol] . ".nbt");
		if($canonicalBlockStatesFile === false){
			throw new AssumptionFailedError("Missing required resource file");
		}
		$stream = new NetworkBinaryStream($canonicalBlockStatesFile);
		$list = [];
		while(!$stream->feof()){
			$list[] = $stream->getNbtCompoundRoot();
		}
		self::$bedrockKnownStates = $list;
	}

	private static function setupLegacyMappings(int $protocol): BlockMapping
	{
		$mapping = new BlockMapping([], []);
		$legacyIdMap = json_decode(file_get_contents(RESOURCE_PATH . "vanilla/block_id_map.json"), true);

		/** @var R12ToCurrentBlockMapEntry[] $legacyStateMap */
		$legacyStateMap = [];
		$legacyStateMapReader = new NetworkBinaryStream(file_get_contents(RESOURCE_PATH . "vanilla/r12_to_current_block_map_" . $protocol . ".bin"));
		$nbtReader = new NetworkLittleEndianNBTStream();
		while(!$legacyStateMapReader->feof()){
			$id = $legacyStateMapReader->getString();
			$meta = $legacyStateMapReader->getLShort();

			$offset = $legacyStateMapReader->getOffset();
			$state = $nbtReader->read($legacyStateMapReader->getBuffer(), false, $offset);
			$legacyStateMapReader->setOffset($offset);
			if(!($state instanceof CompoundTag)){
				throw new \RuntimeException("Blockstate should be a TAG_Compound");
			}
			$legacyStateMap[] = new R12ToCurrentBlockMapEntry($id, $meta, $state);
		}

		/**
		 * @var int[][] $idToStatesMap string id -> int[] list of candidate state indices
		 */
		$idToStatesMap = [];
		foreach(self::getBedrockKnownStates() as $k => $state){
			$idToStatesMap[$state->getString("name")][] = $k;
		}
		foreach($legacyStateMap as $pair){
			$id = $legacyIdMap[$pair->getId()] ?? null;
			if($id === null){
				throw new \RuntimeException("No legacy ID matches " . $pair->getId());
			}
			$data = $pair->getMeta();
			if($data > 15){
				//we can't handle metadata with more than 4 bits
				continue;
			}
			$mappedState = $pair->getBlockState();

			//TODO HACK: idiotic NBT compare behaviour on 3.x compares keys which are stored by values
			$mappedState->setName("");
			$mappedName = $mappedState->getString("name");
			if(!isset($idToStatesMap[$mappedName])){
				throw new \RuntimeException("Mapped new state does not appear in network table");
			}
			foreach($idToStatesMap[$mappedName] as $k){
				$networkState = self::getBedrockKnownStates()[$k];
				if($mappedState->equals($networkState)){
					$mapping->registerMapping($k, $id, $data);
					continue 2;
				}
			}
			throw new \RuntimeException("Mapped new state does not appear in network table");
		}
		return $mapping;
	}

	public static function toStaticRuntimeId(int $id, int $meta = 0) : int{
		self::lazyInit();
		/*
		 * try id+meta first
		 * if not found, try id+0 (strip meta)
		 * if still not found, return update! block
		 */
		return self::getMapping(-1)->toStaticRuntimeId($id, $meta);
	}

	/**
	 * @return int[] [id, meta]
	 */
	public static function fromStaticRuntimeId(int $runtimeId) : array{
		self::lazyInit();
		return self::getMapping(-1)->fromStaticRuntimeId($runtimeId);
	}

	/**
	 * @return CompoundTag[]
	 */
	public static function getBedrockKnownStates() : array{
		self::lazyInit();
		return self::$bedrockKnownStates;
	}

	public static function getMapping(int $protocol)
	{
		self::lazyInit();
		return self::$mappings[$protocol] ?? self::$mappings[ProtocolInfo::CURRENT_PROTOCOL];
	}
}
