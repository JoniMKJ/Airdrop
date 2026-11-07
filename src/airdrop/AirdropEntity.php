<?php

declare(strict_types=1);

namespace airdrop;

use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\entity\NeverSavedWithChunkEntity;
use pocketmine\entity\Skin;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

/**
 * Entidad  de la  skin
 */
final class AirdropEntity extends Human implements NeverSavedWithChunkEntity{

	public static function getNetworkTypeId() : string{
		return EntityIds::PLAYER;
	}

	public function __construct(Location $location, Skin $skin){
		parent::__construct($location, $skin);
		$this->setCanSaveWithChunk(false);
		$this->setNameTag(" ");
		$this->setNameTagVisible(false);
		$this->setNameTagAlwaysVisible(false);
		$this->setSilent(true);
		$this->setHasGravity(false);
	}

	private string $owner = "";

	public function setOwner(string $name) : void{
		$this->owner = strtolower($name);
	}

	public function getOwner() : string{
		return $this->owner;
	}

	private bool $claimed = false;

	public function attack(EntityDamageEvent $source) : void{
		$source->cancel();
		parent::attack($source);
	}

	public function tryClaim() : bool{
		if($this->claimed){
			return false;
		}
		$this->claimed = true;
		return true;
	}
}
