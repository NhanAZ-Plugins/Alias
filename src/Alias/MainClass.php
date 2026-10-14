<?php

namespace Alias;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class MainClass extends PluginBase implements Listener {

    /** @var Config */
    private $settings;

    /** @var string|false */
    private $ipHmacKey = false;

    /** @var bool */
    private $recordAliases = false;

    public function onEnable() {
        $this->settings = new Config($this->getDataFolder() . "config.yml", Config::YAML, array(
            "record-ip-aliases" => false
        ));
        $this->recordAliases = $this->settings->get("record-ip-aliases", false) === true;

        if (!$this->recordAliases) {
            $this->getLogger()->notice("IP-based alias recording is disabled. Review the privacy implications before setting record-ip-aliases to true.");
            return;
        }

        $this->ipHmacKey = $this->loadOrCreateIpHmacKey();
        if ($this->ipHmacKey === false) {
            $this->getLogger()->critical("Alias recording is disabled because its local index key could not be read or created safely.");
            $this->recordAliases = false;
            return;
        }

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event) {
        if (!$this->recordAliases || $this->ipHmacKey === false) {
            return;
        }

        $player = $event->getPlayer();
        $name = $player->getName();
        $ipKey = $this->makeIpKey($player->getAddress());
        if ($ipKey === false) {
            $this->getLogger()->warning("Could not index an alias for a player because the server returned an invalid IP address.");
            return;
        }

        $legacyNames = array_merge(
            $this->readLegacyNames($player->getDisplayName()),
            $this->readLegacyNamesForIp($player->getAddress())
        );
        $ipRecordPath = $this->getIpRecordPath($ipKey);
        if (!$this->ensureDirectory(dirname($ipRecordPath))) {
            $this->getLogger()->error("Could not create the private alias index directory.");
            return;
        }

        $ipRecord = new Config($ipRecordPath, Config::YAML, array("names" => array()));
        $names = $ipRecord->get("names", array());
        if (!is_array($names)) {
            $names = array();
        }
        foreach ($legacyNames as $legacyName) {
            $this->appendName($names, $legacyName);
        }
        $this->appendName($names, $name);
        $ipRecord->set("names", array_values($names));
        $ipRecord->save();

        $playerRecordPath = $this->getPlayerRecordPath($name);
        if (!$this->ensureDirectory(dirname($playerRecordPath))) {
            $this->getLogger()->error("Could not create the private player index directory.");
            return;
        }
        $playerRecord = new Config($playerRecordPath, Config::YAML, array());
        $playerRecord->set("ip_key", $ipKey);
        $playerRecord->set("last_name", $name);
        $playerRecord->save();
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args) {
        if (strtolower($command->getName()) !== "alias") {
            return false;
        }

        if (!$this->recordAliases) {
            $sender->sendMessage(TextFormat::YELLOW . "[Alias] IP-based alias recording and lookup are disabled. A server owner must review and enable record-ip-aliases in config.yml.");
            return true;
        }
        if (!isset($args[0]) || !preg_match('/^[a-z0-9_]{1,16}$/iD', $args[0])) {
            $sender->sendMessage(TextFormat::RED . "§f•§c Sử dụng: " . $command->getUsage());
            return true;
        }

        $requestedName = $args[0];
        $player = $this->getServer()->getPlayer($requestedName);
        if ($player instanceof Player) {
            $ipKey = $this->makeIpKey($player->getAddress());
            $name = $player->getName();
            $names = $ipKey === false ? array() : $this->readIndexedNames($ipKey);
        } else {
            $name = $requestedName;
            $playerRecordPath = $this->getPlayerRecordPath($name);
            if (is_file($playerRecordPath)) {
                $playerRecord = new Config($playerRecordPath, Config::YAML);
                $ipKey = $playerRecord->get("ip_key");
                $names = is_string($ipKey) && preg_match('/^[a-f0-9]{64}$/D', $ipKey)
                    ? $this->readIndexedNames($ipKey)
                    : array();
            } else {
                $names = $this->readLegacyNames($name);
            }
        }

        if (count($names) === 0) {
            $sender->sendMessage(TextFormat::YELLOW . "[Alias] No recorded aliases were found for " . $name . ".");
            return true;
        }

        $sender->sendMessage(TextFormat::GREEN . "[Alias] Recorded aliases for " . $name . ":");
        $sender->sendMessage(TextFormat::BLUE . implode(", ", $names));
        return true;
    }

    /** @return string|false */
    private function loadOrCreateIpHmacKey() {
        $path = $this->getDataFolder() . "ip-index.key";
        if (is_file($path)) {
            $contents = file_get_contents($path);
            if (!is_string($contents)) {
                return false;
            }
            $key = trim($contents);
            return preg_match('/^[a-f0-9]{64}$/D', $key) ? $key : false;
        }

        $strong = false;
        if (function_exists("random_bytes")) {
            try {
                $bytes = random_bytes(32);
                $strong = true;
            } catch (\Throwable $error) {
                $bytes = false;
            }
        } elseif (function_exists("openssl_random_pseudo_bytes")) {
            $bytes = openssl_random_pseudo_bytes(32, $strong);
        } else {
            return false;
        }

        if (!is_string($bytes) || strlen($bytes) !== 32 || !$strong) {
            return false;
        }

        $key = bin2hex($bytes);
        if (file_put_contents($path, $key, LOCK_EX) !== strlen($key)) {
            return false;
        }
        @chmod($path, 0600);
        return $key;
    }

    /** @return string|false */
    private function makeIpKey($ip) {
        if ($this->ipHmacKey === false || !is_string($ip) || !function_exists("inet_pton")) {
            return false;
        }
        $packedIp = inet_pton($ip);
        return $packedIp === false ? false : hash_hmac("sha256", $packedIp, $this->ipHmacKey);
    }

    private function getIpRecordPath($ipKey) {
        return $this->getDataFolder() . "players/index/ip/" . substr($ipKey, 0, 2) . "/" . $ipKey . ".yml";
    }

    private function getPlayerRecordPath($name) {
        $nameKey = hash("sha256", strtolower($name));
        return $this->getDataFolder() . "players/index/name/" . substr($nameKey, 0, 2) . "/" . $nameKey . ".yml";
    }

    private function readIndexedNames($ipKey) {
        $path = $this->getIpRecordPath($ipKey);
        if (!is_file($path)) {
            return array();
        }
        $record = new Config($path, Config::YAML);
        $names = $record->get("names", array());
        return is_array($names) ? $names : array();
    }

    private function readLegacyNames($name) {
        if (!is_string($name) || !preg_match('/^[a-z0-9_]{1,16}$/iD', $name)) {
            return array();
        }

        $playerPath = $this->getDataFolder() . "players/lastip/" . $name[0] . "/" . $name . ".yml";
        if (!is_file($playerPath)) {
            return array();
        }
        $lastIpRecord = new Config($playerPath, Config::YAML);
        $ip = $lastIpRecord->get("lastip");
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return array();
        }

        return $this->readLegacyNamesForIp($ip);
    }

    private function readLegacyNamesForIp($ip) {
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return array();
        }
        $indexPath = $this->getDataFolder() . "players/ip/" . $ip[0] . "/" . $ip . ".txt";
        if (!is_file($indexPath)) {
            return array();
        }
        $index = new Config($indexPath, Config::ENUM);
        $names = $index->getAll(true);
        return is_array($names) ? $names : array();
    }

    private function appendName(array &$names, $name) {
        if (!is_string($name) || !preg_match('/^[a-z0-9_]{1,16}$/iD', $name)) {
            return;
        }
        foreach ($names as $existingName) {
            if (is_string($existingName) && strcasecmp($existingName, $name) === 0) {
                return;
            }
        }
        $names[] = $name;
    }

    private function ensureDirectory($path) {
        return is_dir($path) || @mkdir($path, 0700, true) || is_dir($path);
    }
}
