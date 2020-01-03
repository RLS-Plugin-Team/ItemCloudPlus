<?php

namespace onebone\itemcloud;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\block\BlockIds;
use pocketmine\utils\Utils;
use pocketmine\utils\Config;
use onebone\economyland\EconomyLand;

use pocketmine\Server;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\Inventory;
use pocketmine\math\Vector3;
use pocketmine\scheduler\Task;

class MainClass extends PluginBase implements Listener{
	/**
	 * @var MainClass
	 */
	private static $instance;

	/**
	 * @var ItemCloud[]
	 */
	public $clouds;

	/**
	 * @return MainClass
	 */
	public static function getInstance(){
		return self::$instance;
	}

	/**
	 * @param Player|string $player
	 *
	 * @return ItemCloud|bool
	 */
	public function getCloudForPlayer($player){
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);

		if(isset($this->clouds[$player])){
			return $this->clouds[$player];
		}
		return false;
	}

	/**************************   Non-API part   ***********************************/

	public function onLoad(){
		if(!self::$instance instanceof MainClass){
			self::$instance = $this;
		}
	}

	public function onEnable(){
		$this->breakdate = new Config($this->getDataFolder() ."BreakDate.yml", Config::YAML);
		@mkdir($this->getDataFolder());
		if(!is_file($this->getDataFolder() . "ItemCloud.dat")){
			file_put_contents($this->getDataFolder() . "ItemCloud.dat", serialize([]));
		}
		$data = unserialize(file_get_contents($this->getDataFolder() . "ItemCloud.dat"));

		$this->saveDefaultConfig();
		if(is_numeric($interval = $this->getConfig()->get("auto-save-interval"))){
			$this->getScheduler()->scheduleDelayedRepeatingTask(new SaveTask($this), $interval * 1200, $interval * 1200);
		}

		$this->clouds = [];
		foreach($data as $datam){
			$this->clouds[$datam[1]] = new ItemCloud($datam[0], $datam[1]);
		}
		$this->getServer()->getPluginManager()->registerEvents($this,$this);
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $params) : bool{
		switch($command->getName()){
			case "itemcloud":
				if(!$sender instanceof Player){
					$sender->sendMessage("§cゲーム内で実行してください");
					return true;
				}
				$sub = array_shift($params);
				switch($sub){
					case "register":
					case "reg":
						if(!$sender->hasPermission("itemcloud.command.register")){
							$sender->sendMessage(TextFormat::RED . "You don't have permission to use this command.");
							return true;
						}
						if(isset($this->clouds[strtolower($sender->getName())])){
							$sender->sendMessage("[ItemCloud] §cあなたはアカウントを作成済みです");
							break;
						}
						$this->clouds[strtolower($sender->getName())] = new ItemCloud([], $sender->getName());
						$sender->sendMessage("[ItemCloud] §eあなたのアカウントを作成しました");
						break;
					case "upload":
					case "up":
						if(!$sender->hasPermission("itemcloud.command.upload")){
							$sender->sendMessage(TextFormat::RED . "§cコマンドを実行する権限がありません");
							return true;
						}
						if(!isset($this->clouds[strtolower($sender->getName())])){
							$sender->sendMessage("[ItemCloud] §cアカウントを作成してください");
							break;
						}
						$item = array_shift($params);
						$amount = array_shift($params);
						if(trim($item) === "" or !is_numeric($amount)){
							$sender->sendMessage("Usage: /itemcloud upload <item ID[:item damage]> <count>");
							break;
						}
						$amount = (int) $amount;
						if($amount < 1){
							$sender->sendMessage("[ItemCloud] §c1個以上の個数をしてしてください");
							break;
						}
						$item = Item::fromString($item);
						$item->setCount($amount);

						$count = 0;
						foreach($sender->getInventory()->getContents() as $i){
							if($i->getID() == $item->getID() and $i->getDamage() == $item->getDamage()){
								$count += $i->getCount();
							}
						}
						if($amount <= $count){
							$this->clouds[strtolower($sender->getName())]->addItem($item->getID(), $item->getDamage(), $amount, true);
							$sender->sendMessage("[ItemCloud] §eアップロードしました");
						}else{
							$sender->sendMessage("[ItemCloud] §cアイテムが足りません");
						}
						break;
					case "download":
					case "down":
						if(!$sender->hasPermission("itemcloud.command.download")){
							$sender->sendMessage(TextFormat::RED . "§cコマンドを実行する権限がありません");
							return true;
						}
						$name = strtolower($sender->getName());
						if(!isset($this->clouds[$name])){
							$sender->sendMessage("[ItemCloud] §cアカウントを作成してください");
							break;
						}
						$item = array_shift($params);
						$amount = array_shift($params);
						if(trim($item) === "" or !is_numeric($amount)){
							$sender->sendMessage("Usage: /itemcloud download <item ID[:item damage]> <count>");
							break;
						}
						$amount = (int) $amount;
						if($amount < 1){
							$sender->sendMessage("[ItemCloud] §c1個以上の個数をしてしてください");
							break;
						}
						$item = Item::fromString($item);
						$item->setCount($amount);

						if(!$this->clouds[$name]->itemExists($item->getID(), $item->getDamage(), $amount)){
							$sender->sendMessage("[ItemCloud] §cアイテムが足りません");
							break;
						}

						if($sender->getInventory()->canAddItem($item)){
							$this->clouds[$name]->removeItem($item->getID(), $item->getDamage(), $amount);
							$sender->getInventory()->addItem($item);
							$sender->sendMessage("[ItemCloud] §eダウンロードしました");
						}else{
							$sender->sendMessage("[ItemCloud] §cインベントリに空きがありません");
						}
						break;
					case "give":
						if(!isset($params[1] || !isset($params[2]) || !isset($params[3])){
							$sender->sendMessage("Usage: /itemcloud give <name> <item ID[:item damage]> <count>");
							break;
						}
						 
						if(!isset($this->clouds[$sender->getName()]){
							$sender->sendMessage("[ItemCloud] §cアカウントを作成してください");
							break;
						}
						   
						if(!isset($this->clouds[$params[1]]){
							$sender->sendMessage("[ItemCloud] §c{$params[1]}さんのアカウントが存在しません");
							break;
						}
						   
						if($params[3] < 1){
							$sender->sendMessage("[ItemCloud] §c1個以上の個数にしてしてください");
							break;
						}
						   
						$item = explode(":",$params[2]);
						   
						if(!$this->clouds[$sender->getName()]->itemExists($item[0], $item[1], $params[3])){
							$sender->sendMessage("[ItemCloud] §cアイテムが足りません");
							break;
						}
						  
						$this->clouds[$sender->getName()]->removeItem([$item[0], $item[1], $params[3]); //削除
						   
						$this->clouds[strtolower($params[1])]->addItem($item[0], $item[1], $params[3], true); //追加
						   
						break;
						
					case "list":
						if(!$sender->hasPermission("itemcloud.command.list")){
							$sender->sendMessage(TextFormat::RED . "§cコマンドを実行する権限がありません");
							return true;
						}
						$name = strtolower($sender->getName());
						if(!isset($this->clouds[$name])){
							$sender->sendMessage("[ItemCloud] §cアカウントを作成してください");
							break;
						}
						$output = "[ItemCloud] Item list : \n";
						foreach($this->clouds[$name]->getItems() as $item => $count){
							$output .= "$item : $count\n";
						}
						$sender->sendMessage($output);
						break;
					case "count":
						if(!$sender->hasPermission("itemcloud.command.count")){
							$sender->sendMessage(TextFormat::RED . "§cコマンドを実行する権限がありません");
							return true;
						}
						$name = strtolower($sender->getName());
						if(!isset($this->clouds[$name])){
							$sender->sendMessage("[ItemCloud] §cアカウントを作成してください");
							return true;
						}
						$item = array_shift($params);
						if(trim($item) === ""){
							$sender->sendMessage("Usage: /itemcloud count <item>");
							return true;
						}

						$item = Item::fromString($item);

						if(($count = $this->clouds[$name]->getCount($item->getId(), $item->getDamage())) === false){
							$sender->sendMessage("[ItemCloud] There are no " . $item->getName() . " in your account.");
							break;
						}else{
							$sender->sendMessage("[ItemCloud] Count of " . $item->getName() . " = " . $count);
						}
						break;
					case "onbreak":
						$user_name = $sender->getName();
						if(!$this->breakdate->exists("allbreakdate")){
							if($this->breakdate->exists($user_name)){
								$sender->sendMessage("[ItemCloud] §c既に有効です。");
						        }else{
								$this->breakdate->set($user_name,count($this->breakdate->getAll())+1);
							        $this->breakdate->save();
							        $this->breakdate->reload();
						                $sender->sendMessage("[ItemCloud] §eブロックを壊すと直接アイテムクラウドに行くようになりました。");
						        }
						}else{
							$sender->sendMessage("[ItemCloud] §c管理者によって設定が固定されているため変更できません");
						}
						break;
					case "offbreak":
						$user_name = $sender->getName();
						if(!$this->breakdate->exists("allbreakdate")){
							if(!$this->breakdate->exists($user_name)){
								$sender->sendMessage("[ItemCloud] §c既に無効です。");
						        }else{
							        $this->breakdate->remove($user_name);
								$sender->sendMessage("[ItemCloud] §eブロックを壊しても直接アイテムクラウドに行かなくなりました。");
						        }
						}else{
							$sender->sendMessage("[ItemCloud] §c管理者によって設定が固定されているため変更できません");
						}
						break;
					case "allonbreak":
						if($sender->isOp()){
							if($this->breakdate->exists("allbreakdate")){
								$sender->sendMessage("[Itemcloud] §c既に有効です。");
					                }else{
							        $this->breakdate->set("allbreakdate", "allbreak");
							        $this->breakdate->save();
							        $this->breakdate->reload();
						                $sender->sendMessage("[ItemCloud] §e全員を対象にブロックを壊すと直接アイテムクラウドに行くようになりました。");
							}
	     			  	        }else{
							$sender->sendMessage("§cコマンドを実行する権限がありません。");
						}
						break;
					case "playerbreak":
						if($sender->isOp()){
							if(!$this->breakdate->exists("allbreakdate")){
								$sender->sendMessage("[Itemcloud] §c既に無効です。");
					                }else{
							        $this->breakdate->remove("allbreakdate");
						      	        $this->breakdate->save();
							        $this->breakdate->reload();
						                $sender->sendMessage("[ItemCloud] §e全員を対象とする設定が無効になりました。");
							}
	     			  	        }else{
							$sender->sendMessage("§cこのコマンドを実行する権限がありません。");
						}
						break;
					case "all":
						if(!isset($this->clouds[strtolower($sender->getName())])){
							$sender->sendMessage("[ItemCloud] §eアカウントを作成してください");
						}else{
							$si = $sender->getInventory()->getSize();
							for($is = 1; $is <= $si; ++$is){
								$item = $sender->getInventory()->getItem($is-1);
								$id = $item->getId();
								$meta = $item->getDamage();
								$count = $item->getCount();
								$i = 1;
								if($id !== 0){
									$this->clouds[strtolower($sender->getName())]->addItemBreak($id, $meta, $count, true);
								}
							}	
							$sender->getInventory()->clearAll();
							$sender->sendMessage("[ItemClude] §eインベントリにあるアイテムをすべてアップロードしました");
						}
						break;	
			  	        default:
						$sender->sendMessage("[ItemCloud] Usage: " . $command->getUsage());
				}
				return true;
		}
		return true;
	}
	
	public function onBreak(BlockBreakEvent $event){
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$name = $player->getName();
		$drop = $event->getDrops();
                $level = $block->getLevel();
                $x = floor($block->getX());
                $z = floor($block->getZ());
                $this->land = EconomyLand::getInstance();
                $info= $this->land->getowner($x,$z,$level);
                if($info === false || $name==$info['owner']){
			if(!$player->isOp()){
				if($this->breakdate->exists($name) || $this->breakdate->exists("allbreakdate")){
					if(!isset($this->clouds[strtolower($name)])){
						$player->sendMessage("[ItemCloud] §cアカウントを作成してください");
				                $event->setCancelled();
				        }else{
					        $event->setDrops([]);
                                                foreach($drop as $item){
                                                        $this->clouds[strtolower($name)]->addItemBreak($item->getID(), $item->getDamage(), $item->getCount(), true);
                                                }
					}
				}else{
					$player = $event->getPlayer();
					$drop = $event->getDrops();
					$event->setDrops([]);
					$level = $player->getLevel()->getFolderName();
					foreach($drop as $item){
						#$this->sendItem($player, $item, $event->getBlock(), $event);
						$this->getScheduler()->scheduleDelayedTask(new sendItem($this, $player, $item, $event->getBlock(), $event), 1);
					}
				}
	                }
		}
	}

	public function save(){
		$save = [];
		foreach($this->clouds as $cloud){
			$save[] = $cloud->getAll();
		}
		file_put_contents($this->getDataFolder() . "ItemCloud.dat", serialize($save));
	}

	public function onDisable(){
		$this->save();
		$this->clouds = [];
	}
}

class sendItem extends Task{

	function __construct(PluginBase $owner, $player, $item, $block, $event){
		$this->owner = $owner;
		$this->player = $player;
		$this->item = $item;
		$this->block = $block;
		$this->event = $event;
	}

	function onRun(int $currentTick){
		if(!$this->event->isCancelled()){
			if($this->player->getInventory()->canAddItem($this->item)){
				$this->player->getInventory()->addItem($this->item);
			}else{
				$level = $this->player->getLevel();
				$x = $this->block->x;
				$y = $this->block->y;
				$z = $this->block->z;
				$pos = new Vector3($x, $y, $z);
				$level->dropItem($pos, $this->item);
			}
		}
	}
}
