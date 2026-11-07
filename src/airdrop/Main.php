<?php

declare(strict_types=1);

namespace airdrop;

use muqsit\invmenu\InvMenu;
use muqsit\invmenu\InvMenuHandler;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Skin;
use pocketmine\inventory\Inventory;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat as TF;

final class Main extends PluginBase{

	private const MAX_AMOUNT = 1000;

	private AirdropManager $manager;
	private Skin $skin;

	protected function onEnable() : void{
		if(!InvMenuHandler::isRegistered()){
			InvMenuHandler::register($this);
		}
		$this->saveDefaultConfig();
		try{
			$this->skin = $this->loadSkin();
		}catch(\RuntimeException $e){
			$this->getLogger()->error($e->getMessage());
			return;
		}
		$this->manager = new AirdropManager($this);
		$this->getServer()->getPluginManager()->registerEvents(new AirdropListener($this, $this->manager, $this->skin), $this);
	}

	protected function onDisable() : void{
		if(isset($this->manager)){
			$this->manager->save();
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		switch(strtolower($args[0] ?? "")){
			case "give":
				if(count($args) < 3){
					$sender->sendMessage(TF::RED . "Uso: /airdrop give <jugador> <cantidad>");
					return true;
				}
				$target = $this->getServer()->getPlayerByPrefix($args[1]);
				if($target === null){
					$sender->sendMessage(TF::RED . "Jugador no encontrado.");
					return true;
				}
				$amount = $this->parseAmount($args[2]);
				if($amount === null){
					$sender->sendMessage(TF::RED . "La cantidad debe ser un número entre 1 y " . self::MAX_AMOUNT . ".");
					return true;
				}
				$this->giveAirdrops($target, $amount);
				$sender->sendMessage(TF::GREEN . "Le diste {$amount} airdrop(s) a " . $target->getName() . ".");
				return true;

			case "giveall":
				if(count($args) < 2){
					$sender->sendMessage(TF::RED . "Uso: /airdrop giveall <cantidad>");
					return true;
				}
				$amount = $this->parseAmount($args[1]);
				if($amount === null){
					$sender->sendMessage(TF::RED . "La cantidad debe ser un número entre 1 y " . self::MAX_AMOUNT . ".");
					return true;
				}
				$players = $this->getServer()->getOnlinePlayers();
				foreach($players as $player){
					$this->giveAirdrops($player, $amount);
				}
				$sender->sendMessage(TF::GREEN . "Diste {$amount} airdrop(s) a " . count($players) . " jugador(es).");
				return true;

			case "edit":
				if(!$sender instanceof Player){
					$sender->sendMessage(TF::RED . "Este comando solo se usa dentro del juego.");
					return true;
				}
				$this->openMainMenu($sender);
				return true;
		}

		$sender->sendMessage(TF::YELLOW . "Uso: /airdrop <give|giveall|edit>");
		return true;
	}

	private function loadSkin() : Skin{
		$geometryStream = $this->getResource("skin/airdrop.geo.json");
		$textureStream = $this->getResource("skin/airdrop.rgba");
		$geometry = $geometryStream !== null ? stream_get_contents($geometryStream) : false;
		$texture = $textureStream !== null ? stream_get_contents($textureStream) : false;
		if($geometry === false || $texture === false){
			throw new \RuntimeException("No se encontraron skin/airdrop.geo.json o skin/airdrop.rgba dentro del plugin.");
		}
		return new Skin("airdrop", $texture, "", "geometry.airdrop", $geometry);
	}

	public function getSkin() : Skin{
		return $this->skin;
	}

	private function parseAmount(string $raw) : ?int{
		if(!ctype_digit($raw)){
			return null;
		}
		$amount = (int) $raw;
		return ($amount >= 1 && $amount <= self::MAX_AMOUNT) ? $amount : null;
	}

	private function giveAirdrops(Player $player, int $amount) : void{
		$item = $this->manager->createItem($amount);
		foreach($player->getInventory()->addItem($item) as $left){
			while(!$left->isNull()){
				$drop = $left->pop(min($left->getCount(), $left->getMaxStackSize()));
				$player->getWorld()->dropItem($player->getLocation(), $drop);
			}
		}
		$player->sendMessage(TF::GOLD . "Recibiste " . TF::YELLOW . $amount . TF::GOLD . " airdrop(s).");
	}

	private function openMainMenu(Player $player) : void{
		$player->sendForm(new MenuForm(
			TF::GOLD . TF::BOLD . "AIRDROP",
			"Selecciona qué quieres editar.",
			[TF::BOLD . "EDITAR CONTENIDO", TF::BOLD . "EDITAR ITEM"],
			function(Player $p, int $selected) : void{
				if($selected === 0){
					$this->openContentEditor($p);
				}else{
					$this->editItem($p);
				}
			}
		));
	}

	private function openContentEditor(Player $player) : void{
		$menu = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
		$menu->setName(TF::GOLD . "Contenido del Airdrop");
		$menu->getInventory()->setContents($this->manager->getContents());
		$menu->setInventoryCloseListener(function(Player $p, Inventory $inventory) : void{
			$this->manager->setContents($inventory->getContents());
			$p->sendMessage(TF::GREEN . "Contenido del airdrop guardado.");
		});
		$menu->send($player);
	}

	private function editItem(Player $player) : void{
		$held = $player->getInventory()->getItemInHand();
		if(!$this->manager->setBaseItem($held)){
			$player->sendMessage(TF::RED . "Sostén un cofre (o cualquier bloque) en la mano y vuelve a elegir EDITAR ITEM.");
			return;
		}
		$player->sendMessage(TF::GREEN . "Ahora el airdrop usa el bloque: " . TF::YELLOW . $held->getName());
	}
}