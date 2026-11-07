<?php

declare(strict_types=1);

namespace airdrop;

use pocketmine\block\Air;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\Item;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat as TF;

final class AirdropManager{

	public const TAG = "airdrop";
	private const CHEST_SLOTS = 27;

	private string $path;
	private Item $baseItem;

	/** @var array<int, Item> */
	private array $contents = [];

	public function __construct(private PluginBase $plugin){
		$this->path = $plugin->getDataFolder() . "airdrop.json";
		$this->baseItem = VanillaBlocks::CHEST()->asItem();
		$this->load();
	}

	public function getPlugin() : PluginBase{
		return $this->plugin;
	}

	private function colorize(string $text) : string{
		return str_replace("&", "§", $text);
	}

	private function createLore(array $default) : array{
		return array_map(fn($l) : string => $this->colorize((string) $l), $default);
	}

	public function createItem(int $count = 1) : Item{
		$config = $this->plugin->getConfig();
		$name = $this->colorize((string) $config->get("nombre", "&l&dAirdrop"));

		$lore = $config->get("descripcion", [
			"&7Colócalo para que caiga un cofre",
			"&7con recompensas desde el cielo.",
			"&cNo se puede usar en el spawn."
		]);
		if(is_string($lore)){
			$lore = preg_split('/\r\n|\r|\n/', $lore) ?: [];
		}
		if(!is_array($lore)){
			$lore = [];
		}

		$item = clone $this->baseItem;
		$item->setCount($count);
		$item->setCustomName(TF::RESET . $name);
		$item->setLore($this->createLore($lore));
		$tag = $item->getNamedTag();
		$tag->setByte(self::TAG, 1);
		$item->setNamedTag($tag);
		return $item;
	}

	public function isAirdrop(Item $item) : bool{
		return !$item->isNull() && $item->getNamedTag()->getTag(self::TAG) !== null;
	}

	public function setBaseItem(Item $item) : bool{
		if($item->isNull() || $item->getBlock() instanceof Air){
			return false;
		}
		$clean = clone $item;
		$clean->setCount(1);
		$clean->clearCustomName();
		$clean->setLore([]);
		$tag = $clean->getNamedTag();
		$tag->removeTag(self::TAG);
		$clean->setNamedTag($tag);
		$this->baseItem = $clean;
		$this->save();
		return true;
	}

	public function getBaseItem() : Item{
		return clone $this->baseItem;
	}

	/** @return array<int, Item> */
	public function getContents() : array{
		return array_map(static fn(Item $i) : Item => clone $i, $this->contents);
	}

	/** @param array<int, Item> $contents */
	public function setContents(array $contents) : void{
		$this->contents = [];
		foreach($contents as $slot => $item){
			if(!$item->isNull()){
				$this->contents[$slot] = clone $item;
			}
		}
		$this->save();
	}

	/**
	 * Configuracion de item que estan en  config.yml
	 * @return Item[]
	 */
	public function rollLoot() : array{
		$items = array_values($this->getContents());
		if($items === []){
			return [];
		}

		$config = $this->plugin->getConfig();
		if(strtolower((string) $config->getNested("loot.mode", "random")) === "all"){
			return array_slice($items, 0, self::CHEST_SLOTS);
		}

		$min = max(1, (int) $config->getNested("loot.min-items", 1));
		$max = min(self::CHEST_SLOTS, max($min, (int) $config->getNested("loot.max-items", 9)));
		$amount = min(count($items), random_int($min, $max));

		shuffle($items);
		return array_slice($items, 0, $amount);
	}

	public function save() : void{
		$dir = dirname($this->path);
		if(!is_dir($dir)){
			@mkdir($dir, 0777, true);
		}
		$data = [
			"item" => $this->encode($this->baseItem),
			"contents" => array_map(fn(Item $i) : string => $this->encode($i), $this->contents)
		];
		file_put_contents($this->path, json_encode($data, JSON_PRETTY_PRINT));
	}

	private function load() : void{
		if(!is_file($this->path)){
			return;
		}
		try{
			$data = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
			if(isset($data["item"]) && is_string($data["item"])){
				$this->baseItem = $this->decode($data["item"]);
			}
			foreach(($data["contents"] ?? []) as $slot => $raw){
				$this->contents[(int) $slot] = $this->decode((string) $raw);
			}
		}catch(\Throwable $e){
			$this->plugin->getLogger()->error("No se pudo cargar airdrop.json: " . $e->getMessage());
		}
	}

	private function encode(Item $item) : string{
		return base64_encode((new LittleEndianNbtSerializer())->write(new TreeRoot($item->nbtSerialize())));
	}

	private function decode(string $data) : Item{
		$raw = base64_decode($data, true);
		if($raw === false){
			throw new \InvalidArgumentException("Base64 inválido");
		}
		$tag = (new LittleEndianNbtSerializer())->read($raw)->mustGetCompoundTag();
		return Item::nbtDeserialize($tag);
	}
}