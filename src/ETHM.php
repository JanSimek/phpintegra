<?php
/**
 * INTEGRA is a product line of alarm control panels made by Polish company Satel
 *
 * @author Jan Šimek
 * @version 0.1
 * @license https://opensource.org/licenses/MIT
 */
namespace Satel;

require_once "Commands.php";

/**
 * A PHP class for communication with the Satel ETHM-1 module for the Integra control panels.
 */
class ETHM
{
    // ETHM-1 host
    private $ip;
    private $port;
    private $password;

    /**
     * @var boolean toggles verbose debug messages
     */
    private $debug = false;

    /**
     * @var boolean toggles logging to console
     */
    private $logging = true;
    
    /**
     * @var Command[] Contains response codes and their associated callback functions
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

        $this->commands[0x18] = new Command\x18($this); // Doors opened
        $this->commands[0x19] = new Command\x18($this); // Doors opened long

        $this->commands[0x1A] = new Command\x1A($this); // Clock and basic system status
        
        $this->commands[0x7C] = new Command\x7C($this); // INT-RS/ETHM-1 module version
        $this->commands[0x7E] = new Command\x7E($this); // INTEGRA version

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
