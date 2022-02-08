<?php

declare(strict_types=1);

namespace ken_cir\pmmpoutiserverbot;

use ken_cir\outiserversensouplugin\cache\playercache\PlayerCacheManager;
use ken_cir\outiserversensouplugin\database\playerdata\PlayerDataManager;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\lang\Language;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\utils\Config;
use function count;
use function ob_start;
use function ob_get_contents;
use function ob_flush;
use function ob_end_clean;
use const PTHREADS_INHERIT_CONSTANTS;

final class PMMPOutiServerBot extends PluginBase
{
    /**
     * @var PMMPOutiServerBot $this
     */
    private static self $instance;

    /**
     * DiscordBot用スレッド
     *
     * @var DiscordBotThread
     */
    private DiscordBotThread $discordBotThread;

    protected function onLoad(): void
    {
        self::$instance = $this;
    }

    protected function onEnable(): void
    {
        $this->saveResource("config.yml");

        $requestConfigParam = ["token", "guildId", "consoleChannelId", "chatChannelId"];
        $config = new Config("{$this->getDataFolder()}config.yml", Config::YAML);
        foreach ($requestConfigParam as $key => $str) {
            if ($config->get($str, "") === "") {
                $this->getLogger()->error("config.ymlの $str が設定されていません");
            }
            else {
                unset($requestConfigParam[$key]);
            }
        }

        if (count($requestConfigParam) > 0) {
            $this->getLogger()->critical("config.yml が正常に設定されていません、プラグインを無効化します");
            Server::getInstance()->getPluginManager()->disablePlugin($this);
            return;
        }

        Server::getInstance()->getPluginManager()->registerEvents(new EventListener(), $this);

        $this->discordBotThread = new DiscordBotThread($this->getLogger(),
            $this->getFile(),
            $config->get("token", ""),
            $config->get("guildId", ""),
            $config->get("consoleChannelId", ""),
            $config->get("chatChannelId", ""),
        );

        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function() : void {
                ob_start();
            }
        ), 10);

        // コンソールの出力を読みとってDiscordに送信する
        // ここの period は必ず1に
        $this->getScheduler()->scheduleDelayedRepeatingTask(new ClosureTask(
            function (): void {
                if (!$this->discordBotThread->isRunning()) return;
                $string = ob_get_contents();

                if ($string === "") return;
                $this->discordBotThread->sendConsoleMessage($string);
                ob_flush();
            }
        ), 10, 1);

        // Discord側からのメッセージを取得してforeachで回してサーバー側に送信したりする
        $this->getScheduler()->scheduleDelayedRepeatingTask(new ClosureTask(
            function (): void {
                foreach ($this->discordBotThread->getAllConsoleMessages() as $message) {
                    if ($message === "") continue;
                    Server::getInstance()->dispatchCommand(new ConsoleCommandSender(Server::getInstance(), new Language("jpn")), $message);
                }

                foreach ($this->discordBotThread->getAllChatMessages() as $message) {
                    $content = $message["content"];
                    if ($content === "") continue;
                    Server::getInstance()->broadcastMessage("[Discord:{$message["username"]}] $content");
                }

                foreach ($this->discordBotThread->getAllDiscordVerifys() as $discordVerify) {
                    $playerCache = PlayerCacheManager::getInstance()->getDiscordVerifyCode($discordVerify["code"]);
                    if (!$playerCache) continue;
                    PlayerDataManager::getInstance()->getXuid($playerCache->getXuid())->setDiscordUserid($discordVerify["userid"]);
                    $playerCache->setDiscordVerifyCode(null);
                    $playerCache->setDiscordverifycodeTime(null);
                    $this->discordBotThread->sendDiscordVerifyMessage($playerCache->getName(), $discordVerify["userid"]);
                    $onlineVerifyPlayer = Server::getInstance()->getPlayerByPrefix($playerCache->getName());
                    $onlineVerifyPlayer?->sendMessage("§a[システム] Discordアカウント {$discordVerify["username"]} と連携しました");
                }
            }
        ), 10, 10);

        $this->discordBotThread->start(PTHREADS_INHERIT_CONSTANTS);
        $this->discordBotThread->sendChatMessage("サーバーが起動しました！");
    }

    protected function onDisable(): void
    {
        if (isset($this->discordBotThread)) {
            $this->discordBotThread->stop();
        }

        if (ob_get_contents()) {
            ob_flush();
            ob_end_clean();
        }
    }

    /**
     * @return PMMPOutiServerBot
     */
    public static function getInstance(): PMMPOutiServerBot
    {
        return self::$instance;
    }

    /**
     * @return DiscordBotThread
     */
    public function getDiscordBotThread(): DiscordBotThread
    {
        return $this->discordBotThread;
    }
}