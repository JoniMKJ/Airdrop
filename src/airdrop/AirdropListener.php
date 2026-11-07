<?php

declare(strict_types=1);

namespace airdrop;

use pocketmine\block\BlockTypeIds;
use pocketmine\entity\Skin;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDespawnEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerEntityInteractEvent;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use pocketmine\world\particle\FlameParticle;
use pocketmine\world\Position;
use pocketmine\world\sound\XpLevelUpSound;

final class AirdropListener implements Listener{

	public const MAX_PER_PLAYER = 2;

	/** @var array<string, int> Airdrops colocados*/
	private array $placements = [];

	public function __construct(
		private Main $plugin,
		private AirdropManager $manager,
		private Skin $skin
	){}

	/**
	 * @priority HIGH
	 * @ignoreCancelled
	 */
	public function onPlace(BlockPlaceEvent $event) : void{
		$player = $event->getPlayer();
		$hand = $player->getInventory()->getItemInHand();
		if(!$this->manager->isAirdrop($hand)){
			return;
		}

		$event->cancel();

		$pos = $event->getBlockAgainst()->getPosition();
		$world = $pos->getWorld();

		if($this->isProtected($pos)){
			$player->sendMessage(TF::RED . "No puedes colocar el airdrop en el spawn.");
			return;
		}

		$owned = $this->placementCount($player->getName());
		if($owned >= self::MAX_PER_PLAYER){
			$player->sendMessage(TF::RED . "Ya tienes {$owned} airdrop(s) en el mundo. Espera a que abran uno (o a que se pierda) para colocar otro.");
			return;
		}

		$config = $this->plugin->getConfig();
		$height = max(1, (int) $config->get("fall-height", 13));
		$check = max($height, (int) $config->get("check-height", 20));

		$x = $pos->getFloorX();
		$y = $pos->getFloorY();
		$z = $pos->getFloorZ();

		for($i = $y + 1; $i <= $y + $check; $i++){
			if($world->getBlockAt($x, $i, $z)->getTypeId() !== BlockTypeIds::AIR){
				$player->sendMessage(TF::RED . "No se puede colocar: hay bloques encima.");
				return;
			}
		}

		if($player->hasFiniteResources()){
			$hand->pop();
			$player->getInventory()->setItemInHand($hand);
		}

		$delay = max(1, (int) $config->get("delay-ticks", 40));
		$this->incrementPlacement($player->getName());
		$this->plugin->getScheduler()->scheduleDelayedRepeatingTask(
			new AirdropAnimation($world, $x, $y, $z, $height, $this->manager, $this->skin, $player->getName(), $this),
			$delay,
			1
		);

		$player->sendMessage(TF::GOLD . "¡Airdrop en camino!");
	}

	/**
	 * Clic derecho (o usar) sobre el airdrop.
	 * @priority HIGHEST
	 * @ignoreCancelled
	 */
	public function onInteract(PlayerEntityInteractEvent $event) : void{
		$entity = $event->getEntity();
		if(!$entity instanceof AirdropEntity){
			return;
		}
		$event->cancel();
		$this->claim($event->getPlayer(), $entity);
	}

	/**
	 * Clic izquierdo (pegar) sobre el airdrop.
	 * @priority HIGHEST
	 * @ignoreCancelled
	 */
	public function onAttack(EntityDamageByEntityEvent $event) : void{
		$entity = $event->getEntity();
		if(!$entity instanceof AirdropEntity){
			return;
		}
		$event->cancel();
		$damager = $event->getDamager();
		if($damager instanceof Player){
			$this->claim($damager, $entity);
		}
	}

	private function claim(Player $player, AirdropEntity $entity) : void{
		if(!$entity->tryClaim()){
			return;
		}

		$world = $entity->getWorld();
		$pos = $entity->getLocation();

		$items = $this->manager->rollLoot();
		$claimLimit = max(0, (int) $this->plugin->getConfig()->getNested("loot.claim-limit", 3));
		if($claimLimit > 0 && count($items) > $claimLimit){
			$items = array_slice($items, 0, $claimLimit);
		}
		if($items === []){
			$player->sendMessage(TF::YELLOW . "El airdrop está vacío...");
		}else{
			foreach($items as $item){
				$left = $player->getInventory()->addItem($item);
				foreach($left as $remain){
					$world->dropItem($pos->add(0.5, 1.0, 0.5), $remain);
				}
			}
			$player->sendMessage(TF::GREEN . "¡Abriste el airdrop! Las recompensas son tuyas.");
		}

		$center = $pos->add(0.5, 0.5, 0.5);
		$world->addSound($center, new XpLevelUpSound(1));
		for($i = 0; $i < 16; $i++){
			$angle = deg2rad($i * 22.5);
			$world->addParticle(
				new Vector3(
					$center->x + 1.2 * cos($angle),
					$center->y + 1.0,
					$center->z + 1.2 * sin($angle)
				),
				new FlameParticle()
			);
		}

		$entity->flagForDespawn();
	}

	public function onDespawn(EntityDespawnEvent $event) : void{
		$entity = $event->getEntity();
		if($entity instanceof AirdropEntity && $entity->getOwner() !== ""){
			$this->decrementPlacement($entity->getOwner());
		}
	}

	public function placementCount(string $playerName) : int{
		return $this->placements[strtolower($playerName)] ?? 0;
	}

	public function incrementPlacement(string $playerName) : void{
		$key = strtolower($playerName);
		$this->placements[$key] = ($this->placements[$key] ?? 0) + 1;
	}

	public function decrementPlacement(string $playerName) : void{
		$key = strtolower($playerName);
		if(($this->placements[$key] ?? 0) > 0){
			$this->placements[$key]--;
		}
	}

	private function isProtected(Position $pos) : bool{
		$config = $this->plugin->getConfig();
		if(!(bool) $config->getNested("protected-zone.enabled", true)){
			return false;
		}
		$default = $this->plugin->getServer()->getWorldManager()->getDefaultWorld();
		if($default === null || $pos->getWorld() !== $default){
			return false;
		}
		$radius = (float) $config->getNested("protected-zone.radius", 250);
		return $pos->distance($default->getSpawnLocation()) <= $radius;
	}
}
