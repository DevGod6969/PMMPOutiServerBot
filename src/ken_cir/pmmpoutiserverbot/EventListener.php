<?php

declare(strict_types=1);

namespace ken_cir\pmmpoutiserverbot;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;

final class EventListener implements Listener
{
    public function __construct()
    {
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