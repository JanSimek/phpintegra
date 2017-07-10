<?php
/**
 * INTEGRA is a product line of alarm control panels made by Polish company Satel
 *
 * @author Jan Šimek
 * @version 0.1
 * @license https://opensource.org/licenses/MIT
 * @package Satel\ETHM
 */
namespace Satel;

require_once "CommandList.php";

/**
 * A PHP class for communication with the Satel ETHM-1 module for the Integra control panels.
 */
class ETHM
{
    /** 
     * IP address of the ETHM module 
     * @var string
     */
    private $ip;

    /** 
     * Port number of the ETHM module 
     * @var int
     */
    private $port;

    /** 
     * Password for authenticated commands 
     * @var int
     */
    private $password;

    /**
     * @var boolean Toggles verbose debug messages
     */
    private $debug = false;

    /**
     * @var boolean Toggles logging to console
     */
    private $logging = true;
    
    /**
     * Contains response codes and their associated callback objects
     * @var Command[] 
     */
    private $commands = array();

    /**
     * Primitive logging to the terminal
     *
     * @param debug|info|error $type of log messages
     * @param string $message log message
     */
    public function log($type, $message)
    {
        if (!$this->logging) {
            return;
        }
        
        if ($type == "debug" && $this->debug) {
            echo $message . "\n";   
        } else if ($type == "info" || $type == "error") {
            echo $message . "\n";
        }
    }

    /**
     * @param string $ip        address of the ETHM-1 module
     * @param int $port         default port is 7094
     * @param string $password  optional user passcode to execute privileged commands
     */
    public function __construct($ip = "", $port = 7094, $password = "")
    {
        $this->ip       = $ip;
        $this->port     = $port;
        $this->password = $password;

        $this->registerCmdHandlers();
    }

    public function getIP() { return $this->ip; }
    public function getPort() { return $this->port; }

    /**
     * @abstract Send command to the ETHM module
     */
    public function send($command) {
        $cmd = new Command\OutgoingCommand($this);
        $response = $cmd->sendCommand($command);

        return $this->handleResponse($response);
    }

    /**
     * @abstract Attempts to find and execute a response handler from the $commands array
     */
    private function handleResponse($response)
    {
        // Converts third byte from $raw to decimal representation
        // Integra response format: <code>0xFE 0xFE cmd .. .. crc crc 0xFE 0x0D</code>
        // for some reason I can't simply call bindec() ... why?!
        $cmd = hexdec(bin2hex($response[2]));

        array_key_exists($cmd, $this->commands) or exit("No available handler for command \"" . $cmd . "\"\n");

        return $this->commands[$cmd]->handle($response);
    }

    private function registerCmdHandlers()
    {
        $this->commands[0xEE] = new Command\xEE($this); // Device name

        $this->commands[0x00] = new Command\x00($this); // Zones violation
        $this->commands[0x01] = new Command\x00($this); // Zones tamper
        $this->commands[0x02] = new Command\x00($this); // Zones alarm
        $this->commands[0x03] = new Command\x00($this); // Zones tamper alarm
        $this->commands[0x04] = new Command\x00($this); // Zones alarm memory
        $this->commands[0x05] = new Command\x00($this); // Zones tamper alarm memory
        $this->commands[0x06] = new Command\x00($this); // Zones bypass
        $this->commands[0x07] = new Command\x00($this); // Zones 'no violation trouble'
        $this->commands[0x08] = new Command\x00($this); // Zones 'long violation trouble'
        /*
        0x09 armed partitions (suppressed)      0x09 + 4 bytes 
        0x0A armed partitions (really)          0x0A + 4 bytes 
        0x0B partitions armed in mode 2         0x0B + 4 bytes 
        0x0C partitions armed in mode 3         0x0C + 4 bytes 
        0x0D partitions with 1st code entered   0x0D + 4 bytes 
        0x0E partitions entry time(oid)         0x0E + 4 bytes 
        0x0F partitions exit time >10s          0x0F + 4 bytes 
        0x10 partitions exit time <10s          0x10 + 4 bytes 
        0x11 partitions temporary blocked       0x11 + 4 bytes 
        0x12 partitions blocked for guard round 0x12 + 4 bytes 
        0x13 partitions alarm                   0x13 + 4 bytes 
        0x14 partitions fire alarm              0x14 + 4 bytes 
        0x15 partitions alarm memory            0x15 + 4 bytes 
        0x16 partitions fire alarm memory       0x16 + 4 bytes
        0x17 outputs state                      0x17 + 16/32 bytes (*) 
        */
        $this->commands[0x18] = new Command\x18($this); // Doors opened
        $this->commands[0x19] = new Command\x18($this); // Doors opened long

        $this->commands[0x1A] = new Command\x1A($this); // Clock and basic system status
        /*
        0x1B troubles part 1                    0x1B + 47 bytes (see description below) 
        0x1C troubles part 2                    0x1C + 26 bytes (see description below) 
        0x1D troubles part 3                    0x1D + 60 bytes (see description below) 
        0x1E troubles part 4                    0x1E + 30 bytes (see description below) 
        0x1F troubles part 5                    0x1F + 31 bytes (see description below) 
        0x20 troubles memory part 1             0x20 + 47 bytes (see description below) 
        0x21 troubles memory part 2             0x21 + 39 bytes (see description below)
        0x22 troubles memory part 3             0x22 + 60 bytes (see description below) 
        0x23 troubles memory part 4             0x23 + 30 bytes (see description below) 
        0x24 troubles memory part 5             0x24 + 48 bytes (see description below) 
        0x25 partitions with violated zones     0x25 + 4 bytes 
        0x26 zones isolate                      0x26 + 16/32 bytes (*) 
        0x27 partitions with verified alarms    0x27 + 4 bytes 
        0x28 zones masked                       0x28 + 16/32 bytes (*) (**) 
        0x29 zones masked memory                0x29 + 16/32 bytes (*) (**) 
        0x2A partitions armed in mode 1         0x2A + 4 bytes (**) 
        0x2B partitions with warning alarms     0x2B + 4 bytes (**) 
        0x2C troubles part 6                    0x2C + 45 bytes (see description below) (***) 
        0x2D troubles part 7                    0x2D + 47 bytes (see description below) (***) 
        0x2E troubles memory part 6             0x2E + 45 bytes (see description below) (***) 
        0x2F troubles memory part 7             0x2F + 48 bytes (see description below) (***)
        */
        $this->commands[0x7C] = new Command\x7C($this); // INT-RS/ETHM-1 module version
        /*
        0x7D +1 byte - read zone temperature 0x7D + 3 bytes (answer can be delayed up to 5s): 
        */
        $this->commands[0x7E] = new Command\x7E($this); // INTEGRA version
        $this->commands[0x7F] = new Command\x7F($this); // List commands with new data available
        $this->commands[0xEF] = new Command\xEF($this); // Returned command result
        $this->commands[0x8C] = new Command\x8C($this); // Read event
    }

    public function setDebug($enable)
    {
        $this->debug = $enable;
    }

    public function setLogging($enable)
    {
        $this->logging = $enable;
    }
}
