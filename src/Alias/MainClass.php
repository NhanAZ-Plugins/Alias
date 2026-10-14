<?php

namespace Alias;

use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

final class MainClass extends PluginBase implements Listener {

    private Config $settings;

    private ?string $ipHmacKey = null;

    private bool $recordAliases = false;

    public function onEnable() : void {
        $this->settings = new Config($this->getDataFolder() . "config.yml", Config::YAML, array(
            "record-ip-aliases" => false
        ));
        $this->recordAliases = $this->settings->get("record-ip-aliases", false) === true;

        if (!$this->recordAliases) {
            $this->getLogger()->notice("IP-based alias recording is disabled. Review the privacy implications before setting record-ip-aliases to true.");
            return;
        }

        $this->ipHmacKey = $this->loadOrCreateIpHmacKey();
        if ($this->ipHmacKey === null) {
            $this->getLogger()->critical("Alias recording is disabled because its local index key could not be read or created safely.");
            $this->recordAliases = false;
            return;
        }

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event) : void {
        if (!$this->recordAliases || $this->ipHmacKey === null) {
            return;
        }

        $player = $event->getPlayer();
        $name = $player->getName();
        $ip = $player->getNetworkSession()->getIp();
        $ipKey = $this->makeIpKey($ip);
        if ($ipKey === null) {
            $this->getLogger()->warning("Could not index an alias for a player because the server returned an invalid IP address.");
            return;
        }

        $legacyNames = array_merge(
            $this->readLegacyNames($name),
            $this->readLegacyNamesForIp($ip)
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

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool {
        if (strtolower($command->getName()) !== "alias") {
            return false;
        }

        if (!$this->recordAliases) {
            $sender->sendMessage(TextFormat::YELLOW . "[Alias] IP-based alias recording and lookup are disabled. A server owner must review and enable record-ip-aliases in config.yml.");
            return true;
        }
        if (!isset($args[0]) || !is_string($args[0]) || !preg_match('/^[a-z0-9_]{1,16}$/iD', $args[0])) {
            $sender->sendMessage(TextFormat::RED . "§f•§c Sử dụng: " . $command->getUsage());
            return true;
        }

        $requestedName = $args[0];
        $player = $this->getServer()->getPlayerExact($requestedName);
        if ($player instanceof Player) {
            $ipKey = $this->makeIpKey($player->getNetworkSession()->getIp());
            $name = $player->getName();
            $names = $ipKey === null ? array() : $this->readIndexedNames($ipKey);
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

    private function loadOrCreateIpHmacKey() : ?string {
        $path = $this->getDataFolder() . "ip-index.key";
        if (is_file($path)) {
            $contents = file_get_contents($path);
            if (!is_string($contents)) {
                return null;
            }
            $key = trim($contents);
            return preg_match('/^[a-f0-9]{64}$/D', $key) ? $key : null;
        }

        try {
            $bytes = random_bytes(32);
        } catch (\Throwable $error) {
            return null;
        }

        if (strlen($bytes) !== 32) {
            return null;
        }

        $key = bin2hex($bytes);
        if (file_put_contents($path, $key, LOCK_EX) !== strlen($key)) {
            return null;
        }
        @chmod($path, 0600);
        return $key;
    }

    private function makeIpKey(string $ip) : ?string {
        if ($this->ipHmacKey === null) {
            return null;
        }
        $packedIp = inet_pton($ip);
        return $packedIp === false ? null : hash_hmac("sha256", $packedIp, $this->ipHmacKey);
    }

    private function getIpRecordPath(string $ipKey) : string {
        return $this->getDataFolder() . "players/index/ip/" . substr($ipKey, 0, 2) . "/" . $ipKey . ".yml";
    }

    private function getPlayerRecordPath(string $name) : string {
        $nameKey = hash("sha256", strtolower($name));
        return $this->getDataFolder() . "players/index/name/" . substr($nameKey, 0, 2) . "/" . $nameKey . ".yml";
    }

    private function readIndexedNames(string $ipKey) : array {
        $path = $this->getIpRecordPath($ipKey);
        if (!is_file($path)) {
            return array();
        }
        $record = new Config($path, Config::YAML);
        $names = $record->get("names", array());
        if (!is_array($names)) {
            return array();
        }
        $validNames = array();
        foreach ($names as $name) {
            $this->appendName($validNames, $name);
        }
        return $validNames;
    }

    private function readLegacyNames(string $name) : array {
        if (!preg_match('/^[a-z0-9_]{1,16}$/iD', $name)) {
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

    private function readLegacyNamesForIp(string $ip) : array {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return array();
        }
        $indexPath = $this->getDataFolder() . "players/ip/" . $ip[0] . "/" . $ip . ".txt";
        if (!is_file($indexPath)) {
            return array();
        }
        $index = new Config($indexPath, Config::ENUM);
        $names = $index->getAll(true);
        $validNames = array();
        foreach ($names as $name) {
            $this->appendName($validNames, $name);
        }
        return $validNames;
    }

    private function appendName(array &$names, mixed $name) : void {
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

    private function ensureDirectory(string $path) : bool {
        return is_dir($path) || @mkdir($path, 0700, true) || is_dir($path);
    }
}
