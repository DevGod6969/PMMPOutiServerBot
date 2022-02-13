<?php

declare(strict_types=1);

namespace ken_cir\pmmpoutiserverbot;

use ken_cir\outiserversensouplugin\database\playerdata\PlayerDataManager;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;

class EventListener implements Listener
{
    public function __construct()
    {
    }

    /**
     * @priority MONITOR
     *
     * @param PlayerLoginEvent $event
     * @return void
     */
    public function onPlayerLogin(PlayerLoginEvent $event): void
    {
        $player = $event->getPlayer();
        if (($playerData = PlayerDataManager::getInstance()->getXuid($player->getXuid())) and $playerData->getDiscordUserid()) {
            PMMPOutiServerBot::getInstance()->getDiscordBotThread()->addDiscordUserTag($player->getXuid(), $playerData->getDiscordUserid());
        }
    }

    /**
     * @priority MONITOR
     *
     * @param PlayerJoinEvent $event
     * @return void
     */
    public function onPlayerJoin(PlayerJoinEvent $event): void
    {
        $player = $event->getPlayer();
        PMMPOutiServerBot::getInstance()->getDiscordBotThread()->sendChatMessage("{$player->getName()}がサーバーに参加しました");
    }

    /**
     * @priority MONITOR
     *
     * @param PlayerQuitEvent $event
     * @return void
     */
    public function onPlayerQuit(PlayerQuitEvent $event): void
    {
        $player = $event->getPlayer();
        PMMPOutiServerBot::getInstance()->getDiscordBotThread()->sendChatMessage("{$player->getName()}がサーバーから退出しました");
    }

    /**
     * @priority MONITOR
     *
     * @param PlayerChatEvent $event
     * @return void
     */
    public function onPlayerChat(PlayerChatEvent $event): void
    {
        PMMPOutiServerBot::getInstance()->getDiscordBotThread()->sendChatMessage($event->getFormat());
    }
}