<?php

declare(strict_types=1);

namespace airdrop;

use pocketmine\block\BlockTypeIds;
use pocketmine\entity\Location;
use pocketmine\entity\Skin;
use pocketmine\math\Vector3;
use pocketmine\scheduler\Task;
use pocketmine\world\particle\FlameParticle;
use pocketmine\world\particle\HugeExplodeParticle;
use pocketmine\world\sound\ExplodeSound;
use pocketmine\world\World;

final class AirdropAnimation extends Task{

	private float $y = 0.0;
	private bool $started = false;
	private ?AirdropEntity $entity = null;

	public function __construct(
		private World $world,
		private int $x,
		private int $baseY,
		private int $z,
		private int $height,
		private AirdropManager $manager,
		private Skin $skin,
		private string $owner = "",
		private ?AirdropListener $listener = null
	){
	}

	public function onRun() : void{
		$world = $this->world;

		if(!$world->isLoaded()){
			$wasNull = $this->entity === null;
			$this->cleanup();
			if($wasNull){
				$this->releasePlacement();
			}
			$this->getHandler()?->cancel();
			return;
		}
		if(!$world->isChunkLoaded($this->x >> 4, $this->z >> 4)){
			return;
		}

		$landingY = $this->baseY + 1;
		$config = $this->manager->getPlugin()->getConfig();
		$speed = max(0.05, min(1.0, (float) $config->get("fall-speed", 0.2)));

		if(!$this->started){
			$this->started = true;

			$y = $this->baseY + $this->height;
			while($y >= $landingY && !$this->isAir($y)){
				$y--;
			}
			if($y < $landingY){
				$this->releasePlacement();
				$this->getHandler()?->cancel();
				return;
			}
			$this->y = (float) $y;
			$this->spawnEntity($this->y);
			$this->ring();
			return;
		}

		if($this->entity === null || $this->entity->isClosed()){
			$this->getHandler()?->cancel();
			return;
		}

		$target = $this->y - $speed;
		$blockY = (int) floor($target);

		if($blockY <= $landingY){
			$this->land($landingY);
			return;
		}
		if(!$this->isAir($blockY - 1)){
			$this->land($blockY);
			return;
		}

		$this->y = $target;
		$this->moveEntity($this->y);
		$this->ring();
	}

	private function releasePlacement() : void{
		$this->listener?->decrementPlacement($this->owner);
	}

	private function spawnEntity(float $y) : void{
		$pos = new Vector3($this->x + 0.5, $y, $this->z + 0.5);
		$this->entity = new AirdropEntity(Location::fromObject($pos, $this->world, 0.0, 0.0), $this->skin);
		if($this->owner !== ""){
			$this->entity->setOwner($this->owner);
		}
		$this->entity->spawnToAll();
	}

	private function moveEntity(float $y) : void{
		if($this->entity === null){
			return;
		}
		$this->entity->teleport(new Vector3($this->x + 0.5, $y, $this->z + 0.5), $this->entity->getLocation()->getYaw(), 0.0);
	}

	private function cleanup() : void{
		if($this->entity !== null && !$this->entity->isClosed()){
			$this->entity->flagForDespawn();
		}
		$this->entity = null;
	}

	private function isAir(int $y) : bool{
		return $this->world->getBlockAt($this->x, $y, $this->z)->getTypeId() === BlockTypeIds::AIR;
	}

	private function ring() : void{
		for($i = 0; $i < 8; $i++){
			$angle = deg2rad($i * 45);
			$this->world->addParticle(
				new Vector3(
					$this->x + 0.5 + 0.8 * cos($angle),
					$this->y + 0.5,
					$this->z + 0.5 + 0.8 * sin($angle)
				),
				new FlameParticle()
			);
		}
	}

	private function land(int $y) : void{
		$world = $this->world;
		if($this->entity !== null && !$this->entity->isClosed()){
			$this->entity->teleport(
				new Vector3($this->x + 0.5, (float) $y, $this->z + 0.5),
				random_int(0, 359),
				0.0
			);
			$this->entity = null; 
		}

		$center = new Vector3($this->x + 0.5, $y + 0.5, $this->z + 0.5);
		$world->addParticle($center, new HugeExplodeParticle());
		$world->addSound($center, new ExplodeSound());

		$this->getHandler()?->cancel();
	}
}
