<?php

declare(strict_types=1);

namespace ken_cir\pmmpoutiserverbot;

use AttachableLogger;
use Discord\Discord;
use Discord\Exceptions\IntentException;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\User\Activity;
use Discord\Parts\User\Member;
use Discord\WebSockets\Intents;
use Error;
use Exception;
use JetBrains\PhpStorm\Pure;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use pocketmine\thread\Thread;
use pocketmine\utils\TextFormat;
use React\EventLoop\Factory;
use Threaded;
use function count;
use function serialize;
use function unserialize;
use function preg_replace;
use function strlen;
use function substr;

final class DiscordBotThread extends Thread
{
    private AttachableLogger $logger;

    private string $dir;

    /**
     * DiscordBotのトークン
     *
     * @var string
     */
    private string $token;

    /**
     * ギルドID
     *
     * @var string
     */
    private string $guildId;

    /**
     * コンソールチャンネルID
     *
     * @var string
     */
    private string $consoleChannelId;

    /**
     * チャットチャンネルID
     *
     * @var string
     */
    private string $chatChannelId;

    /** Minecraft -> Discord */
    /**
     * Minecraft -> Discord
     * のコンソールキュー
     *
     * @var Threaded
     */
    private Threaded $minecraftConsoleQueue;

    /**
     * Minecraft -> Discord
     * のチャットキュー
     *
     * @var Threaded
     */
    private Threaded $minecraftChatQueue;

    /**
     * Minecraft -> Discord
     * の連携認証キュー
     *
     * @var Threaded
     */
    private Threaded $minecraftVerifyQueue;

    /** Discord -> Minecraft */
    /**
     * Discord -> Minecraft
     * のコンソールキュー
     *
     * @var Threaded
     */
    private Threaded $discordConsoleQueue;

    /**
     * Discord -> Minecraft
     * のチャットキュー
     *
     * @var Threaded
     */
    private Threaded $discordChatQueue;

    /**
     * Discord -> Minecraft
     * の連携認証キュー
     *
     * @var Threaded
     */
    private Threaded $discordVerifyQueue;

    #[Pure] public function __construct(AttachableLogger $logger, string $dir, string $token, string $guildId, string $consoleChannelId, string $chatChannelId)
    {
        $this->logger = $logger;
        $this->dir = $dir;
        $this->token = $token;
        $this->guildId = $guildId;
        $this->consoleChannelId = $consoleChannelId;
        $this->chatChannelId = $chatChannelId;
        $this->minecraftConsoleQueue = new Threaded();
        $this->minecraftChatQueue = new Threaded();
        $this->minecraftVerifyQueue = new Threaded();
        $this->discordConsoleQueue = new Threaded();
        $this->discordChatQueue = new Threaded();
        $this->discordVerifyQueue = new Threaded();
    }

    protected function onRun(): void
    {
        $this->registerClassLoaders();

        include "{$this->dir}vendor/autoload.php";

        $loop = Factory::create();

        try {
            $logger = new Logger('Logger');
            $logger->pushHandler(new StreamHandler('php://stdout', Logger::WARNING));
            $discord = new Discord([
                "token" => $this->token,
                "loop" => $loop,
                "logger" => $logger,
                'loadAllMembers' => true,
                'intents' => Intents::GUILDS | Intents::GUILD_MESSAGES | Intents::DIRECT_MESSAGES | Intents::GUILD_MEMBERS
            ]);
        }
        catch (IntentException $exception) {
            $this->logger->error("File: {$exception->getFile()}\nLine: {$exception->getLine()}\nMessage: {$exception->getMessage()}\nTrace: {$exception->getTraceAsString()}");
            $this->logger->critical("DiscordBotのログインに失敗しました");
            unset($this->token);
            $this->isKilled = true;
            return;
        }

        unset($this->token);

        $loop->addPeriodicTimer(1, function () use ($discord) {
            if ($this->isKilled) {
                $discord->close();
                $discord->getLoop()->stop();
            }
        });

        $loop->addPeriodicTimer(1, function () use ($discord) {
            $this->task($discord);
        });

        $discord->on('ready', function (Discord $discord) {
            echo "Bot is ready." . PHP_EOL;
            $activity = new Activity($discord);
            $activity->name = "マインクラフト for おうち鯖";
            $activity->type = Activity::TYPE_PLAYING;
            $discord->updatePresence($activity);

            $discord->on('message', function (Message $message) use ($discord) {
                if ($message->author instanceof Member ? $message->author->user->bot : $message->author->bot or $message->type !== Message::TYPE_NORMAL or $message->content === "") return;
                // DM
                if ($message->channel->type === Channel::TYPE_DM) {
                    $code = (int)$message->content;
                    if ($code === 0) return;
                    $this->discordVerifyQueue[] = serialize([
                        "code" => $code,
                        "userid" => $message->author->id,
                        "username" => "{$message->author->username}#{$message->author->discriminator}"
                    ]);
                }
                // テキストチャンネル
                elseif ($message->channel->type === Channel::TYPE_TEXT) {
                    // コンソールチャンネルからのメッセージだった場合は
                    if ($message->channel_id === $this->consoleChannelId) {
                        $this->discordConsoleQueue[] = serialize($message->content);
                    }
                    // チャットチャンネルからのメッセージだった場合は
                    elseif ($message->channel_id === $this->chatChannelId) {
                        $this->discordChatQueue[] = serialize([
                            "username" => "{$message->author->username}#{$message->author->discriminator}",
                            "content" => $message->content
                        ]);
                    }
                }
            });
        });

        $discord->run();
    }

    public function stop()
    {
        $this->isKilled = true;
    }

    private function task(Discord $discord)
    {
        try {
            $guild = $discord->guilds->get('id', $this->guildId);
            $consoleChannel = $guild->channels->get('id', $this->consoleChannelId);
            $chatChannel = $guild->channels->get('id', $this->chatChannelId);

            while (count($this->minecraftConsoleQueue) > 0) {
                $message = unserialize($this->minecraftConsoleQueue->shift());//
                $message = preg_replace(['/]0;.*%/', '/[\x07]/', "/Server thread\//"], '', TextFormat::clean(substr($message, 0, 2000)));
                if ($message === "") continue;
                if (strlen($message) < 2000) {
                    $consoleChannel->sendMessage("```$message```");
                }
            }

            while (count($this->minecraftChatQueue) > 0) {
                $message = unserialize($this->minecraftChatQueue->shift());
                $message = preg_replace(['/]0;.*%/', '/[\x07]/', "/Server thread\//"], '', TextFormat::clean(substr($message, 0, 2000)));
                if ($message === "") continue;
                if (strlen($message) < 2000) {
                    $chatChannel->sendMessage($message);
                }
            }

            while (count($this->minecraftVerifyQueue) > 0) {
                $message = unserialize($this->minecraftVerifyQueue->shift());
                $user = $discord->users->get('id', $message["userid"]);
                $user->sendMessage("Xboxアカウント {$message["name"]} と連携しました");
            }
        }
        catch (Error | Exception $exception) {
            $this->logger->error("File: {$exception->getFile()}\nLine: {$exception->getLine()}\nMessage: {$exception->getMessage()}\nTrace: {$exception->getTraceAsString()}");
        }
    }

    public function sendConsoleMessage(string $message)
    {
        $this->minecraftConsoleQueue[] = serialize($message);
    }

    public function sendChatMessage(string $message)
    {
        $this->minecraftChatQueue[] = serialize($message);
    }

    public function sendDiscordVerifyMessage(string $name, string $userid)
    {
        $this->minecraftVerifyQueue[] = serialize([
            "name" => $name,
            "userid" => $userid
        ]);
    }

    public function getAllConsoleMessages(): array
    {
        $messages = [];
        while (count($this->discordConsoleQueue) > 0) {
            $messages[] = unserialize($this->discordConsoleQueue->shift());
        }

        return $messages;
    }

    public function getAllChatMessages(): array
    {
        $messages = [];
        while (count($this->discordChatQueue) > 0) {
            $messages[] = unserialize($this->discordChatQueue->shift());
        }

        return $messages;
    }

    public function getAllDiscordVerifys(): array
    {
        $verifys = [];
        while (count($this->discordVerifyQueue) > 0) {
            $verifys = unserialize($this->discordVerifyQueue->shift());
        }

        return $verifys;
    }
}