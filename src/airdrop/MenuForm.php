<?php

declare(strict_types=1);

namespace airdrop;

use Closure;
use pocketmine\form\Form;
use pocketmine\player\Player;

final class MenuForm implements Form{

	/**
	 * @param string[] $buttons
	 * @param Closure(Player, int) : void $onSelect
	 */
	public function __construct(
		private string $title,
		private string $content,
		private array $buttons,
		private Closure $onSelect
	){
		$this->buttons = array_values($buttons);
	}

	public function jsonSerialize() : array{
		return [
			"type" => "form",
			"title" => $this->title,
			"content" => $this->content,
			"buttons" => array_map(static fn(string $text) : array => ["text" => $text], $this->buttons)
		];
	}

	public function handleResponse(Player $player, $data) : void{
		if($data === null){
			return; 
		}
		if(!is_int($data) || !isset($this->buttons[$data])){
			return;
		}
		($this->onSelect)($player, $data);
	}
}