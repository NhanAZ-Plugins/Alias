<?php

namespace AliasTest {
    class Fixture {
        public static $dataFolder;
        public static $players = array();
        public static $eventsRegistered = false;
    }

    class Logger {
        public $messages = array();
        public function notice($message) { $this->messages[] = $message; }
        public function critical($message) { $this->messages[] = $message; }
        public function warning($message) { $this->messages[] = $message; }
        public function error($message) { $this->messages[] = $message; }
    }

    class PluginManager {
        public function registerEvents($listener, $plugin) { Fixture::$eventsRegistered = true; }
    }

    class Server {
        private $manager;
        public function __construct() { $this->manager = new PluginManager(); }
        public function getPluginManager() { return $this->manager; }
        public function getPlayer($name) { return isset(Fixture::$players[strtolower($name)]) ? Fixture::$players[strtolower($name)] : null; }
    }
}

namespace pocketmine\plugin {
    class PluginBase {
        private $logger;
        private $server;
        public function __construct() { $this->logger = new \AliasTest\Logger(); $this->server = new \AliasTest\Server(); }
        public function getDataFolder() { return \AliasTest\Fixture::$dataFolder; }
        public function getLogger() { return $this->logger; }
        public function getServer() { return $this->server; }
    }
}

namespace pocketmine\command {
    class CommandSender {
        public $messages = array();
        public function sendMessage($message) { $this->messages[] = $message; }
    }
    class Command {
        private $name;
        public function __construct($name) { $this->name = $name; }
        public function getName() { return $this->name; }
        public function getUsage() { return "/alias <name>"; }
    }
}

namespace pocketmine\event {
    interface Listener {}
}

namespace pocketmine\event\player {
    class PlayerJoinEvent {
        private $player;
        public function __construct($player) { $this->player = $player; }
        public function getPlayer() { return $this->player; }
    }
}

namespace pocketmine {
    class Player extends \pocketmine\command\CommandSender {
        private $name;
        private $ip;
        public function __construct($name, $ip) { $this->name = $name; $this->ip = $ip; }
        public function getName() { return $this->name; }
        public function getDisplayName() { return $this->name; }
        public function getAddress() { return $this->ip; }
    }
}

namespace pocketmine\utils {
    class TextFormat {
        const RED = "";
        const GREEN = "";
        const BLUE = "";
        const YELLOW = "";
    }

    class Config {
        const YAML = 2;
        const ENUM = 3;
        private $path;
        private $data;
        public static $stored = array();
        public function __construct($path, $type = self::YAML, array $defaults = array()) {
            $this->path = $path;
            if(isset(self::$stored[$path])) {
                $this->data = self::$stored[$path];
            } elseif(is_file($path)) {
                $contents = file_get_contents($path);
                $decoded = @unserialize($contents);
                $this->data = is_array($decoded) ? $decoded : $defaults;
                self::$stored[$path] = $this->data;
            } else {
                $this->data = $defaults;
                self::$stored[$path] = $this->data;
            }
        }
        public function get($key, $default = false) { return array_key_exists($key, $this->data) ? $this->data[$key] : $default; }
        public function set($key, $value = null) {
            if(func_num_args() === 1) { $this->data[$key] = true; }
            else { $this->data[$key] = $value; }
            self::$stored[$this->path] = $this->data;
        }
        public function save() {
            $directory = dirname($this->path);
            if(!is_dir($directory)) { mkdir($directory, 0700, true); }
            self::$stored[$this->path] = $this->data;
            file_put_contents($this->path, serialize($this->data));
        }
        public function getAll($keys = false) { return $keys ? array_keys($this->data) : $this->data; }
    }
}

namespace {
    require dirname(__DIR__) . "/src/Alias/MainClass.php";

    function check($condition, $message) {
        if(!$condition) { throw new \RuntimeException($message); }
    }

    function fixtureDirectory($label) {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "alias-privacy-" . $label . "-" . uniqid() . DIRECTORY_SEPARATOR;
        mkdir($path, 0700, true);
        \AliasTest\Fixture::$dataFolder = $path;
        \AliasTest\Fixture::$players = array();
        \AliasTest\Fixture::$eventsRegistered = false;
        return $path;
    }

    function configureOptIn($path) {
        $config = new \pocketmine\utils\Config($path . "config.yml", \pocketmine\utils\Config::YAML, array());
        $config->set("record-ip-aliases", true);
        $config->save();
    }

    $defaultFolder = fixtureDirectory("default");
    $plugin = new \Alias\MainClass();
    $plugin->onEnable();
    $plugin->onJoin(new \pocketmine\event\player\PlayerJoinEvent(new \pocketmine\Player("DefaultUser", "192.0.2.5")));
    check(!\AliasTest\Fixture::$eventsRegistered, "Default configuration must not register IP recording.");
    check(!is_dir($defaultFolder . "players/index"), "Default configuration must not create an IP index.");
    $plugin->onCommand(new \pocketmine\command\CommandSender(), new \pocketmine\command\Command("alias"), "alias", array("DefaultUser"));

    $folder = fixtureDirectory("opt-in");
    configureOptIn($folder);
    $plugin = new \Alias\MainClass();
    $plugin->onEnable();
    $alice = new \pocketmine\Player("Alice", "192.0.2.50");
    $bob = new \pocketmine\Player("Bob", "192.0.2.50");
    $plugin->onJoin(new \pocketmine\event\player\PlayerJoinEvent($alice));
    $plugin->onJoin(new \pocketmine\event\player\PlayerJoinEvent($bob));
    check(\AliasTest\Fixture::$eventsRegistered, "Explicit opt-in should register the join listener.");

    $indexedFiles = array();
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder . "players/index", \FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file) {
        $indexedFiles[] = $file->getPathname();
        check(strpos($file->getPathname(), "192.0.2.50") === false, "Raw IP must not appear in new index paths.");
        check(strpos(file_get_contents($file->getPathname()), "192.0.2.50") === false, "Raw IP must not appear in new index contents.");
    }
    check(count($indexedFiles) >= 3, "Expected a keyed IP index and two player indexes.");

    $sender = new \pocketmine\command\CommandSender();
    $plugin->onCommand($sender, new \pocketmine\command\Command("alias"), "alias", array("Alice"));
    check(count($sender->messages) === 2 && strpos($sender->messages[1], "Alice, Bob") !== false, "Shared-address aliases should be returned from the keyed index.");

    $beforeInvalidQuery = count($sender->messages);
    $plugin->onCommand($sender, new \pocketmine\command\Command("alias"), "alias", array("../config"));
    check(count($sender->messages) === $beforeInvalidQuery + 1, "Unsafe username paths should be rejected.");

    $legacyFolder = fixtureDirectory("legacy");
    configureOptIn($legacyFolder);
    $legacyLastIpPath = $legacyFolder . "players/lastip/A/Alice.yml";
    $legacyLastIp = new \pocketmine\utils\Config($legacyLastIpPath, \pocketmine\utils\Config::YAML, array());
    $legacyLastIp->set("lastip", "198.51.100.9");
    $legacyLastIp->save();
    $legacyIndexPath = $legacyFolder . "players/ip/1/198.51.100.9.txt";
    $legacyIndex = new \pocketmine\utils\Config($legacyIndexPath, \pocketmine\utils\Config::ENUM, array());
    $legacyIndex->set("Alice");
    $legacyIndex->set("FormerName");
    $legacyIndex->save();

    $plugin = new \Alias\MainClass();
    $plugin->onEnable();
    $legacySender = new \pocketmine\command\CommandSender();
    $plugin->onCommand($legacySender, new \pocketmine\command\Command("alias"), "alias", array("Alice"));
    check(count($legacySender->messages) === 2 && strpos($legacySender->messages[1], "FormerName") !== false, "Validated legacy aliases should remain readable as fallback.");

    $plugin->onJoin(new \pocketmine\event\player\PlayerJoinEvent(new \pocketmine\Player("Alice", "198.51.100.9")));
    check(is_file($legacyLastIpPath) && is_file($legacyIndexPath), "Legacy user files must be preserved rather than deleted.");
    $migratedPlayerKey = hash("sha256", "alice");
    $migratedPath = $legacyFolder . "players/index/name/" . substr($migratedPlayerKey, 0, 2) . "/" . $migratedPlayerKey . ".yml";
    check(is_file($migratedPath), "Legacy name should be indexed without changing its original file.");

    echo "Alias privacy opt-in, keyed storage, path validation and legacy preservation checks passed.\n";
}
