<?php
/**
 * INTEGRA is a product line of alarm control panels made by Polish company Satel
 *
 * @author Jan Šimek
 * @version 0.1
 * @license https://opensource.org/licenses/MIT
 */
namespace Satel;

/**
 * A PHP class for communication with the Satel ETHM-1 module for the Integra control panels.
 */
class Integra
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
     * @var callable[] Contains response codes and their associated callback functions
     */
    private $commandList = array();

    const PARTITION	= "00"; // partition (1..32)
    const ZONE		= "01"; // zone (1..128), in INTEGRA 256 PLUS - up to 256
    const USER		= "02"; // user (1..255) (*)
    const EXPANDER	= "03"; // expander/LCD (129..192 - expander, 193..210 - LCD)
    const OUTPUT	= "04"; // output (1..128), in INTEGRA 256 PLUS - up to 256
    const ZONEX		= "05"; // zone (1..128) with partition assignment (*), in INTEGRA 256 PLUS - up to 256

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

        switch ($type) {
            case "debug":
                if ($this->debug) {
                    echo $message . "\n";
                }
                break;
            case "info":
            case "error":
            default:
                echo $message . "\n";
                break;
        }
    }

    /**
     * Converts third byte from $raw to decimal representation
     *
     * Integra response format: <code>0xFE 0xFE cmd .. .. crc crc 0xFE 0x0D</code>
     *
     * @return int response code
     */
    public function getCommand($raw)
    {
        // for some reason I can't simply call bindec()
        return hexdec(bin2hex($raw[2]));
    }

    /**
     * Attempts to find and execute a response handler from the $commandList array of functions
     */
    private function handleResponse($response)
    {
        $cmd = $this->getCommand($response);
        array_key_exists($cmd, $this->commandList) or exit("No available handler for command \"" . $cmd . "\"");

        return $this->commandList[$cmd]($response);
    }

    /**
     * Query Integra for the name of an object with $id
     * @param int $id object id
     * @param Integra::ZONE|Integra::PARTITION|Integra::OUTPUT $type
     */
    private function getObjectName($id, $type)
    {
        $this->sendCommand("EE" . $type . sprintf("%02s", $id));
    }

    /**
     * No clue what's happening here. Copied from Marcin's IntegraPy project
     * @link https://github.com/mkorz/IntegraPy/blob/master/integrademo.py#L185
     */
    private function checkZones($raw)
    {
        $this->log("info", "Checking zones.");

        $violated = array();

        // FIXME: this checks for 16*8=128 zones; number of zones varies with different Integra models
        for ($i = 0; $i < 16; $i++) {
            for ($b = 0; $b < 8; $b++) {
                if (pow(2, $b) & hexdec(bin2hex($raw[$i+3]))) { // 0xFE 0xFE 0x00 ..
                    $number = 8 * $i + $b + 1;
                    // TODO: self::PARTITION, self::OUTPUT, etc.
                    $violated[$number] = $this->sendCommand("EE" . self::ZONEX . sprintf("%02s", dechex($number)));
                }
            }
        }

        return $violated;
    }

    private function registerCmdHandlers()
    {
        // 0xEE: Get name
        $this->commandList[0xEE] = function ($raw) {
            // 0xFE 0xFE 0xEE 0xXX 0xXX 0xXX N A M E .. .. .. 0xFE 0x0D
            //                  |    |    |  \_ 6. start of an ASCII string encoded in decimal numbers
            //                  |    |    \_ 5. device type/function
            //                  |    \_ 4. device number
            //                  \_ 3. device type

            $response = $this->trimWrapper($raw, 6); // see above
            $response = $this->toBytearray($response);
            $response = array_map("chr", array_map("hexdec", $response));

            return implode($response); // TODO: strip trailing whitespace?
        };

        // 0x00: Zones violation
        $this->commandList[0x00] = function ($raw) {
            return $this->checkZones($raw);
        };

        // 0x01: Zones tamper
        $this->commandList[0x01] = function ($raw) {
            return $this->checkZones($raw);
        };

        // 0x02: Zones alarm
        $this->commandList[0x02] = function ($raw) {
            return $this->checkZones($raw);
        };

        // 0x03: Zones tamper alarm
        $this->commandList[0x03] = function ($raw) {
            return $this->checkZones($raw);
        };

        // 0x04: Zones alarm memory
        $this->commandList[0x04] = function ($raw) {
            return $this->checkZones($raw);
        };

        // 0x05: Zones tamper alarm memory
        $this->commandList[0x05] = function ($raw) {
            return $this->checkZones($raw);
        };

        // 0x06: Zones bypass
        $this->commandList[0x06] = function ($raw) {
            return $this->checkZones($raw);
        };

        // 0x07: Zones 'no violation trouble'
        $this->commandList[0x07] = function ($raw) {
            return $this->checkZones($raw);
        };

        // 0x08: Zones 'long violation trouble'
        $this->commandList[0x08] = function ($raw) {
            return $this->checkZones($raw);
        };

        // 0x18: Doors opened
        $this->commandList[0x18] = function ($raw) {
            return bin2hex($raw); // TODO
        };

        // 0x19: Doors opened long
        $this->commandList[0x19] = function ($raw) {
            return bin2hex($raw); // TODO
        };

        // 0x1A: Clock and basic system status
        $this->commandList[0x1A] = function ($raw) {

            $response = $this->trimWrapper($raw);

            $deviceNames = array(
                0 => array("INTEGRA 24"),
                1 => array("INTEGRA 32"),
                2 => array("INTEGRA 64 or 64 PLUS"),
                3 => array("INTEGRA 128 or 128 PLUS"),
                4 => array("INTEGRA 128-WRL"),
                8 => array("INTEGRA 256 PLUS")
                );
            $deviceId = (hexdec(bin2hex($raw[11])) & ((1 << 4) - 1)); // <- ($data2 & 0xff)
            $type = (array_key_exists($deviceId, $deviceNames) ? $deviceNames[$deviceId][0] : "unknown (id " . $deviceId . ")");
            $this->log("info", "Integra type : " . $type);

            // Date YYYYMMDD
            $year   = $response[0].$response[1].$response[2].$response[3];
            $month  = $response[4].$response[5];
            $day    = $response[6].$response[7];

            // Time HHMMSS
            $hours   = $response[8].$response[9];
            $minutes = $response[10].$response[11];
            $seconds = $response[12].$response[13];

            $weekdays = array("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday");

            $data1 = hexdec(bin2hex($raw[10]));
            $data2 = hexdec(bin2hex($raw[11]));

            $datetime = sprintf("%s-%s-%s %s %s:%s:%s", $year, $month, $day, $weekdays[($data1 & ((1 << 3) - 1))], $hours, $minutes, $seconds);
            $this->log("info", "Integra time : " . $datetime);

            // Explanation:
            // 		1) masking: http://stackoverflow.com/a/10090450/1585128
            //      2) bit value: http://stackoverflow.com/a/2643735/1585128
            // Also see the OpenHAB project: http://git.io/vnu3x (but they do it wrong?)
            $service    = (($data1 >> 7) & 1);
            $troubles   = (($data1 >> 6) & 1);
            $acu100     = (($data2 >> 7) & 1);
            $intrx      = (($data2 >> 6) & 1);
            $troublemem = (($data2 >> 5) & 1);
            $grade32set = (($data2 >> 4) & 1);

            $this->log("info", "Service mode : " . ($service ? "yes" : "no"));
            $this->log("info", "Troubles     : " . ($troubles ? "yes" : "no"));
            $this->log("info", "ACU-100      : " . ($acu100 ? "yes" : "no"));
            $this->log("info", "INT-Rx       : " . ($intrx ? "yes" : "no"));
            $this->log("info", "Troubles mem.: " . ($troublemem ? "yes" : "no"));
            $this->log("info", "Grade32Set   : " . ($grade32set ? "yes" : "no"));

            return array(
                "type"      => $type,
                "datetime"  => $datetime,
                "service"   => $service,
                "troubles"  => $troubles,
                "acu100"    => $acu100,
                "intrx"     => $intrx,
                "troublemem" => $troublemem,
                "grade32set" => $grade32set
                );
        };

        // 0x7C: INT-RS/ETHM-1 module version
        //       11 bytes: version, e.g. '12320120527'
        //       1  byte : .0 - 1 = module can serve 32 data bytes for zones/outputs
        // NOTE: Modules ealier than 2013-11-08 do not know this command, so they will not reply
        //       Since mine is one of those older ones, I couldn't test this command
        $this->commandList[0x7C] = function ($raw) {
            $response = $this->trimWrapper($raw);
            $response = $this->toBytearray($response);

            $version = array_map("chr", array_map("hexdec", array_slice($response, 0, 10)));
            $version = vsprintf("%s.%s%s %s%s%s%s-%s%s-%s%s", $version);
            $this->log("info", "ETHM-1 Version: " . $version);

            $canserve32 = (($response[11] >> 0) & 1);
            $this->log("info", "Can serve  32b: " . ($canserve32 ? "yes" : "no"));

            return array("version" => $version, "canserve32" => $canserve32);
        };

        // 0x7E: INTEGRA version
        //       1  byte : INTEGRA type
        //       11 bytes: version, e.g. '12320120527'
        //       1  byte : language (1 = English, 7 = Czech, others unknown)
        //       1  byte : stored in flash (255 = true, otherwise false)
        $this->commandList[0x7E] = function ($raw) {
            $response = $this->trimWrapper($raw);
            $response = $this->toBytearray($response);

            // id => name, zones, outputs
            $deviceNames = array(
                0   => array("INTEGRA 24", 24, 20),
                1   => array("INTEGRA 32", 32, 32),
                2   => array("INTEGRA 64", 64, 64),
                3   => array("INTEGRA 128", 128, 128),
                4   => array("INTEGRA 128-WRL SIM300", 128, 128),
                132 => array("INTEGRA 128-WRL LEON", 128, 128),
                66  => array("INTEGRA 64 PLUS", 64, 64),
                67  => array("INTEGRA 128 PLUS", 128, 128),
                72  => array("INTEGRA 256 PLUS", 256, 256)
                );
            $deviceId = hexdec($response[0]);

            if(array_key_exists($deviceId, $deviceNames))
            {
            	$type = $deviceNames[$deviceId][0];
            	$zones = $deviceNames[$deviceId][1];
            	$outputs = $deviceNames[$deviceId][2];
            }
            else
            {
            	$type = "unknown (id " . $deviceId . ")";
            	$zones = "?";
            	$outputs = "?";
            }
            
            $this->log("info", "Integra type : " . $type . "(" . $zones . " zones)");

            $version = array_map("chr", array_map("hexdec", array_slice($response, 1, 11)));
            $version = vsprintf("%s.%s%s %s%s%s%s-%s%s-%s%s", $version);
            $this->log("info", "Version      : " . $version);

            
            $language = hexdec($response[12]); // TODO: 1 = English, 7 = Czech, ...
            $flashed = hexdec($response[13]); // TODO: 255 = stored in flash

            return array(
                "type"      => $type,
                "zones"		=> $zones,
                "outputs"	=> $outputs,
                "version"   => $version,
                "language"  => $language,
                "flashed"   => $flashed
                );
        };

        // 0xEF: returned command result
        $this->commandList[0xEF] = function ($raw) {
            switch (bin2hex($raw[3])) {
                case 0x00:
                    $this->log("info", "ok");
                    return true;
                case 0x01:
                    $this->log("error", "requesting user code not found");
                    return false;
                case 0x02:
                    $this->log("error", "no access");
                    return false;
                case 0x03:
                    $this->log("error", "selected user does not exist");
                    return false;
                case 0x04:
                    $this->log("error", "selected user already exists");
                    return false;
                case 0x05:
                    $this->log("error", "wrong code or code already exists");
                    return false;
                case 0x06:
                    $this->log("error", "telephone code already exists");
                    return false;
                case 0x07:
                    $this->log("error", "changed code is the same");
                    return false;
                case 0x08:
                    $this->log("error", "other error");
                    return false;
                case 0x11:
                    $this->log("error", "can not arm, but can use force arm");
                    return false;
                case 0x12:
                    $this->log("error", "can not arm");
                    return false;
                case 0x8: // FIXME: is this right? Docs say "0x8?"
                    $this->log("error", "other errors");
                    return false;
                case 0xFF:
                    $this->log("info", "command accepted (i.e. data length and crc ok), will be processed");
                    return false;
                default:
                    $this->log("error", "unknown result code " . bin2hex($raw[3]));
                    return false;
            }
        };

        // 0x8C: read event
        $this->commandList[0x8C] = function ($raw) {
            $response = $this->trimWrapper($raw);
            $response = $this->toBytearray($response);

            $byte1 = hexdec(bin2hex($raw[3]));

            // Show all bits in a byte. For debugging.
            //for ($bit = 7; $bit >= 0; $bit--) {
            //    echo "Bit " . $bit . ": " . (($byte1 >> $bit) & 1) . "\n";
            //}

            $year = $this->sumBits($byte1, 7, 6);
            $yearmod = date("Y") % 4;
            $this->log("info", "Year: " . $year . " should equal => (" . date("Y") . " % 4) = " . $yearmod);
            
            $notempty = (($byte1 >> 5) & 1);
            $this->log("info", "Not empty    : " . ($notempty ? "yes" : "no"));
            $epresent = (($byte1 >> 4) & 1);
            $this->log("info", "Event present: " . ($epresent ? "yes" : "no"));

            $monitoringStatus = function ($code) {
            
                switch ($code) {
                    case "00":
                        return "[$code] new event, not processed by monitoring service";
                    case "01":
                        return "[$code] event sent";
                    case "10":
                        return "[$code] should not occur";
                    case "11":
                        return "[$code] event not monitored";
                    default:
                        return "[$code] wrong code";
                }
            };

            $s2status = $monitoringStatus((($byte1 >> 3) & 1) . (($byte1 >> 2) & 1));
            $s1status = $monitoringStatus((($byte1 >> 1) & 1) . (($byte1 >> 0) & 1));
            $this->log("info", "S2           : " . $s2status);
            $this->log("info", "S1           : " . $s1status);

            $byte2 = hexdec(bin2hex($raw[4]));

            $eventClass = function ($event) {
            
                switch ($event) {
                    case "000":
                        return "[$event] zone and tamper alarms";
                    case "001":
                        return "[$event] partition and expander alarms";
                    case "010":
                        return "[$event] arming, disarming, alarm clearing";
                    case "011":
                        return "[$event] zone bypasses and unbypasses";
                    case "100":
                        return "[$event] access control";
                    case "101":
                        return "[$event] troubles";
                    case "110":
                        return "[$event] user functions";
                    case "111":
                        return "[$event] system events";
                    default:
                        return "[$event] wrong event code";
                }
            };
            $ekkk = $eventClass((($byte2 >> 7) & 1) . (($byte2 >> 6) & 1) . (($byte2 >> 5) & 1));
            $this->log("info", "Event (KKK)  : " . $ekkk);

            //$day = bindec((($byte2 >> 4) & 1) . (($byte2 >> 3) & 1) . (($byte2 >> 2) & 1) . (($byte2 >> 1) & 1) . (($byte2 >> 0) & 1));
            $day = $this->sumBits($byte2, 4, 0);

            $byte3 = hexdec(bin2hex($raw[5]));

            $month = $this->sumBits($byte3, 7, 4);

            $this->log("info", "Date:        : " . $day . "/" . $month);

            $time1 = (($byte3 >> 3) & 1) . (($byte3 >> 2) & 1) . (($byte3 >> 1) & 1) . (($byte3 >> 0) & 1);

            $byte4 = hexdec(bin2hex($raw[6]));

            $time2 = (($byte4 >> 7) & 1) . (($byte4 >> 6) & 1) . (($byte4 >> 5) & 1) . (($byte4 >> 4) & 1) . (($byte4 >> 3) & 1) . (($byte4 >> 2) & 1) . (($byte4 >> 1) & 1) . (($byte3 >> 0) & 1);

            $time = bindec($time1 . $time2);
            $hours = floor($time/60);
            $minutes = $time - $hours * 60;
            $this->log("info", "Time         : " . $hours . ":" . $minutes);

            $byte5 = hexdec(bin2hex($raw[7]));

            $partition = $this->sumBits($byte5, 7, 3);
            $this->log("info", "Partition No.: " . $partition);

            $restore = (($byte5 >> 2) & 1) ;
            $this->log("info", "Restore      : " . ($restore ? "yes" : "no"));

            $evcode1 = (($byte5 >> 1) & 1) . (($byte5 >> 0) & 1);

            $byte6 = hexdec(bin2hex($raw[8]));
            $evcode2 = (($byte6 >> 7) & 1) . (($byte6 >> 6) & 1) . (($byte6 >> 5) & 1) . (($byte6 >> 4) & 1) . (($byte6 >> 3) & 1) . (($byte6 >> 2) & 1) . (($byte6 >> 1) & 1) . (($byte6 >> 0) & 1);

            $evcode = bindec($evcode1 . $evcode2);
            $this->log("info", "Event code   : " . $evcode);

            foreach ($this->eventList as $event) {
                if ($event[0] ==  $evcode && $event[1] == $restore) {
                    $evtext = $event[3];
                    $this->log("info", "Event text   : " . $event[3]);
                }
            }

            // TODO:
            //      byte6 - CCcccccccc - event code - use command 0x8F to convert to text (we keep statuses stored in an array)
            //      byte7 - nnnnnnnn - source number (e.g. zone number, user number) (see Appendix 1)
            //      byte8 - SSS - object number (0..7)
            //              uuuuu - user control number

            $evindex = $response[8] . $response[9] . $response[10];
            $this->log("info", "Event index  : " . $evindex);

            return array(
                "yearmod"   => $yearmod,
                "notempty"  => $notempty,
                "epresent"  => $epresent,
                "s2status"  => $s2status,
                "s1status"  => $s1status,
                "ekkk"      => $ekkk,
                "date"      => $day . "/" . $month,
                "time"      => $hours . ":" . $minutes,
                "partition" => $partition,
                "restore"   => $restore,
                "evcode"    => $evcode,
                "evtext"    => $evtext,
                "evindex"   => $evindex
                );
        };
    }

    /**
     * @abstract This function exists because I don't know how to use bitwise operations.
     *           Remember that bits start from higher to lower: 7 6 5 4 3 2 1 0
     * @return sum of bits from $start to $end converted from binary to decimal
     */
    public function sumBits($byte, $start, $end)
    {
        $sum = "";
        for ($bit = $start; $bit >= $end; $bit--) {
            $sum .= (($byte >> $bit) & 1);
        }
        return bindec($sum);
    }

    public function sendCommand($command)
    {
        sleep(1); // ETHM-1 is as slow as a negro

        $fp = fsockopen($this->ip, $this->port, $fp_errno, $fp_errstr, 2);
        stream_set_timeout($fp, 30) or die("Couldn't set timeout for socket.");

        if (!$fp) {
            $this->log("error", "$fp_errstr ($fp_errno)");
        } else {
            // If any byte of the frame (i.e. cmd, d1, d2, ..., dn, crc.high, crc.low) to be sent is equal 0xFE,
            // the following two bytes must be sent instead of single 0xFE byte: 0xFE, 0xF0.
            // In such case only single 0xFE should be used to update crc.
            $command = str_replace("FE", "FEF0", $command . $this->checksum($command));

            $message = hex2bin("FEFE" . $command . "FE0D");

            fwrite($fp, $message);

            // while (!feof($this->fp)) { $response = fgets($this->fp, 100); }
            $response = fread($fp, 100);

            fclose($fp);

            // Received a "Busy!" response
            if (substr(bin2hex($response), 0, 16) == "1042757379210d0a") {
                $this->log("info", "Integra is busy! Re-trying in 5 seconds.");
                sleep(5);
                return $this->sendCommand($command);
            }

            $this->log("debug", "Sending command  : " . $command . " with checksum " . $this->checksum($command) . " (" . hexdec($this->checksum($command)) . ")");
            $this->log("debug", "Sending message  : " . $this->bin2hexstring($message));

            if (empty($response)) {
                $this->log("error", "No response received from Integra.");
                return null;
            }

            $this->log("debug", "Response         : " . $this->bin2hexstring($response));

            //if ($this->debug) {
            //    print_r($this->toBytearray(bin2hex($response)));
            //}

            // Validate the integra message format
            if (bin2hex(substr($response, 0, 2)) != "fefe" && $this->bin2hexstring(substr($response, -2)) != "fe0d") {
                $this->log("error", "Invalid packet received: " . bin2hex($response));
                return null;
            }

            // Validate the checksum
            $calcchecksum = $this->checksum($this->trimWrapper($response, 2));
            $cmdchecksum = substr(bin2hex($response), strlen(bin2hex($response)) - 8, 4);

            if ($calcchecksum != $cmdchecksum) {
                $this->log("error", "Invalid checksum received: " . $calcchecksum . " != " . $cmdchecksum);
                return null;
            }

            return $this->handleResponse($response);
        }
    }

    /**
     * @abstract I have no idea what's going on here
     * @author   mkorz
     * @link     https://github.com/mkorz
     */
    private function checksum($str)
    {
        $crc = 0x147A;

        // For all successive bytes b = cmd, d1, d2, ..., dn perform the crc update steps <= ! don't pass footer and header !
        for ($b = 0; $b < strlen($str); $b += 2) {
            // rotate crc one bit left
            $crc = (($crc << 1) & 0xFFFF) | ($crc & 0x8000) >> 15;
            $crc = $crc ^ 0xFFFF;
            // crc = crc + crc.high + b, e.g. if crc=0xFEDC and b=0xA9 then: 0xFEDC + 0xFE + 0xA9 = 0x0083
            $crc = $crc + ($crc >> 8) + hexdec($str[$b] . $str[$b+1]);
        }
        $crc = sprintf("%02s%02s", dechex(($crc >> 8) & 0xFF), dechex($crc & 0xFF));

        return $crc;
    }

    private function toBytearray($string)
    {
        //$string = bin2hex($string);
        $strlen = mb_strlen($string);
        while ($strlen) {
            $array[] = mb_substr($string, 0, 2, "UTF-8");
            $string = mb_substr($string, 2, $strlen, "UTF-8");
            $strlen = mb_strlen($string);
        }

        return $array;
    }

    private function bin2hexstring($bin)
    {
        $hexstring = bin2hex($bin);
        $hexstring = strtoupper(chunk_split($hexstring, 2, " "));

        return $hexstring;
    }

    /**
     * @abstract Trims the starting and ending bytes from the response
     *
     * Integra ususally sends packets in the following format:
     * <pre>FE FE cmd .. .. .. .. crc crc FE 0D</pre>
     * so it's 2 header, 1 cmd, X cmd arguments, X message, 2 checksum, 2 footer = 7 total
     * @param front first returned char
     * @param back last chopped char
     */
    private function trimWrapper($raw, $front = 3, $back = 4)
    {
        return bin2hex(substr($raw, $front, strlen($raw) - ($front + $back)));
    }

    public function setDebug($enable)
    {
        $this->debug = $enable;
    }

    public function setLogging($enable)
    {
        $this->logging = $enable;
    }
    
    /**
     * - the first column is the event code (CCcccccccc)
     * - the second column is new/restore (R)
     * - the third column is kind of long description (see Appendix 2)
     * - the fourth column is event text description
     * @var array[]
     */
    private $eventList = array(
    array(1  , 0, 6, 'Voice messaging aborted'),
    array(2  , 0, 3, 'Change of user access code'),
    array(2  , 1, 3, 'Change of user access code '),
    array(3  , 0, 6, 'Change of user access code '),
    array(4  , 0, 6, 'Zones bypasses '),
    array(5  , 0, 6, 'Zones reset'),
    array(6  , 0, 6, 'Change of options'),
    array(7  , 0, 6, 'Permission for service access'),
    array(7  , 1, 6, 'Permission for service access removed'),
    array(8  , 0, 6, 'Addition of user'),
    array(9  , 0, 6, 'New user'),
    array(10 , 0, 6, 'Edition of user'),
    array(11 , 0, 6, 'User changed'),
    array(12 , 0, 6, 'Removal of user'),
    array(13 , 0, 6, 'User removed'),
    array(14 , 0, 6, 'Breaking user code'),
    array(15 , 0, 6, 'User access code broken'),
    array(16 , 0, 6, 'Addition of master'),
    array(17 , 0, 6, 'Edition of master'),
    array(18 , 0, 6, 'Removal of master'),
    array(19 , 0, 4, 'RS-downloading started'),
    array(19 , 1, 4, 'RS-downloading finished'),
    array(20 , 0, 6, 'TEL-downloading started'),
    array(21 , 0, 6, 'Monitoring station 1A test'),
    array(22 , 0, 6, 'Monitoring station 1B test'),
    array(23 , 0, 6, 'Monitoring station 2A test'),
    array(24 , 0, 6, 'Monitoring station 2B test'),
    array(26 , 0, 2, 'Access to cash machine granted'),
    array(27 , 0, 3, 'Breaking user code'),
    array(27 , 1, 3, 'Breaking user code'),
    array(28 , 0, 3, 'User access code broken'),
    array(28 , 1, 3, 'User access code broken'),
    array(29 , 0, 7, 'Automatically removed temporal user'),
    array(30 , 0, 0, 'Service access automatically blocked'),
    array(31 , 0, 0, 'Main panel software updated'),
    array(32 , 0, 4, 'System settings stored in FLASH memory'),
    array(33 , 0, 0, 'Starter started'),
    array(34 , 0, 0, 'Starter started from RESET jumper'),
    array(36 , 0, 7, 'Removal of single user'),
    array(37 , 0, 2, 'First access code entered'),
    array(38 , 0, 3, 'Voice messaging aborted'),
    array(38 , 1, 3, 'Voice messaging aborted'),
    array(39 , 0, 1, 'Vibration sensors test ok'),
    array(40 , 0, 6, 'Change of prefix'),
    array(41 , 0, 0, 'Change of winter time to summer time'),
    array(42 , 0, 0, 'Change of summer time to winter time'),
    array(43 , 0, 6, 'Guard round'),
    array(44 , 0, 5, 'First access code expired'),
    array(45 , 0, 2, 'First access code cancelled'),
    array(46 , 0, 7, 'Remote (telephone) control started'),
    array(46 , 1, 7, 'Remote (telephone) control finished'),
    array(47 , 0, 10, 'Remote switch turned on'),
    array(47 , 1, 10, 'Remote switch turned off'),
    array(48 , 0, 30, 'TCP/IP connection started (Internet)'),
    array(48 , 1, 30, 'TCP/IP connection finished (Internet)'),
    array(49 , 0, 30, 'TCP/IP connection failed (Internet)'),
    array(50 , 0, 31, 'IP address'),
    array(51 , 0, 4, 'Invalidation of system settings in FLASH'),
    array(52 , 0, 6, 'Service note cleared'),
    array(53 , 0, 1, 'Vibration sensors test interrupted'),
    array(54 , 0, 30, 'TCP/IP connection started (DloadX)'),
    array(54 , 1, 30, 'TCP/IP connection finished (DloadX)'),
    array(55 , 0, 30, 'TCP/IP connection failed (DloadX)'),
    array(56 , 0, 30, 'TCP/IP connection started (GuardX)'),
    array(56 , 1, 30, 'TCP/IP connection finished (GuardX)'),
    array(57 , 0, 30, 'TCP/IP connection failed (GuardX)'),
    array(58 , 0, 30, 'TCP/IP connection started (GSM socket)'),
    array(58 , 1, 30, 'TCP/IP connection finished (GSM socket)'),
    array(59 , 0, 30, 'TCP/IP connection failed (GSM socket)'),
    array(60 , 0, 30, 'TCP/IP connection started (GSM http)'),
    array(60 , 1, 30, 'TCP/IP connection finished (GSM http)'),
    array(61 , 0, 30, 'TCP/IP connection failed (GSM http)'),
    array(62 , 0, 6, 'User access'),
    array(63 , 0, 6, 'User exit'),
    array(64 , 0, 4, 'Keypad temporary blocked'),
    array(65 , 0, 4, 'Reader temporary blocked'),
    array(66 , 0, 1,'Arming in "Stay" mode '),
    array(67 , 0, 1,'Armin in "Stay, delay=0" mode '),
    array(68 , 0, 0,'System real-time clock set '),
    array(69 , 0, 6,'Troubles memory cleared '),
    array(70 , 0, 6,'User logged in '),
    array(71 , 0, 6,'User logged out '),
    array(72 , 0, 6,'Door opened from LCD keypad '),
    array(73 , 0,13,'Door opened '),
    array(74 , 0, 6,'System restored '),
    array(75 , 0, 0,'ETHM/GPRS key changed '),
    array(76 , 0, 6,'Messaging test started '),
    array(77 , 0, 1,'Alarm monitoring delay '),
    array(78 , 0, 1,'Network cable unplugged '),
    array(78 , 1, 1,'Network cable ok '),
    array(79 , 0, 9,'Messaging trouble '),
    array(80 , 0, 9,'Messaging doubtful '),
    array(81 , 0, 9,'Messaging ok '),
    array(82 , 0, 9,'Messaging confirmed '),
    array(83 , 0, 1,'3 wrong access codes '),
    array(84 , 0, 1,'Alarm - proximity card reader tamper '),
    array(84 , 1, 1,'Proximity card reader restore '),
    array(85 , 0, 4,'Unauthorised door opening '),
    array(86 , 0, 3,'User exit '),
    array(86 , 1, 3,'User exit '),
    array(87 , 0, 2,'Partition temporary blocked '),
    array(88 , 0, 0,'GSM module trouble '),
    array(88 , 1, 0,'GSM module ok '),
    array(89 , 0, 4,'Long opened door '),
    array(89 , 1, 4,'Long opened door closed '),
    array(90 , 0, 0,'Downloading suspended '),
    array(91 , 0, 0,'Downloading started '),
    array(92 , 0, 1,'Alarm - module tamper (verification error) '),
    array(92 , 1, 1,'Module tamper restore (verification ok) '),
    array(93 , 0, 1,'Alarm - module tamper (lack of presence) '),
    array(93 , 1, 1,'Module tamper restore (presence ok) '),
    array(94 , 0, 1,'Alarm - module tamper (TMP input) '),
    array(94 , 1, 1,'Module tamper restore (TMP input) '),
    array(95 , 0,12,'Output overload '),
    array(95 , 1,12,'Output overload restore '),
    array(96 , 0,12,'No output load '),
    array(96 , 1,12,'Output load present '),
    array(97 , 0, 1,'Long zone violation '),
    array(97 , 1, 1,'Long zone violation restore '),
    array(98 , 0, 1,'No zone violation '),
    array(98 , 1, 1,'No zone violation restore '),
    array(99 , 0, 1,'Zone violation '),
    array(99 , 1, 1,'Zone restore '),
    array(100 , 0, 1,'Medical request (button) '),
    array(100 , 1, 1,'Release of medical request button '),
    array(101 , 0, 1,'Medical request (remote) '),
    array(101 , 1, 1,'Remote medical request restore '),
    array(110 , 0, 1,'Fire alarm '),
    array(110 , 1, 1,'Fire alarm zone restore '),
    array(111 , 0, 1,'Fire alarm (smoke detector) '),
    array(111 , 1, 1,'Smoke detector zone restore '),
    array(112 , 0, 1,'Fire alarm (combustion) '),
    array(112 , 1, 1,'Combustion zone restore '),
    array(113 , 0, 1,'Fire alarm (water flow) '),
    array(113 , 1, 1,'Water flow detection restore '),
    array(114 , 0, 1,'Fire alarm (temperature sensor) '),
    array(114 , 1, 1,'Temperature sensor zone restore '),
    array(115 , 0, 1,'Fire alarm (button) '),
    array(115 , 1, 1,'Release of fire alarm button '),
    array(116 , 0, 1,'Fire alarm (duct) '),
    array(116 , 1, 1,'Duct zone restore '),
    array(117 , 0, 1,'Fire alarm (flames detected) '),
    array(117 , 1, 1,'Flames detection zone restore '),
    array(120 , 0, 1,'PANIC alarm (keypad) '),
    array(121 , 0, 2,'DURESS alarm '),
    array(122 , 0, 1,'Silent PANIC alarm '),
    array(122 , 1, 1,'Silent panic alarm zone restore '),
    array(123 , 0, 1,'Audible PANIC alarm '),
    array(123 , 1, 1,'Audible panic alarm zone restore '),
    array(126 , 0, 5,'Alarm - no guard '),
    array(130 , 0, 1,'Burglary alarm '),
    array(130 , 1, 1,'Zone restore '),
    array(131 , 0, 1,'Alarm (perimeter zone) '),
    array(131 , 1, 1,'Perimeter zone restore '),
    array(132 , 0, 1,'Alarm (interior zone) '),
    array(132 , 1, 1,'Interior zone restore '),
    array(133 , 0, 1,'Alarm (24h burglary zone) '),
    array(133 , 1, 1,'24h burglary zone restore '),
    array(134 , 0, 1,'Alarm (entry/exit zone) '),
    array(134 , 1, 1,'Entry/exit zone restore '),
    array(135 , 0, 1,'Alarm (day/night zone) '),
    array(135 , 1, 1,'Day/night zone restore'),
    array(136 , 0, 1,'Alarm (exterior zone) '),
    array(136 , 1, 1,'Exterior zone restore '),
    array(137 , 0, 1,'Alarm (tamper perimeter) '),
    array(137 , 1, 1,'Tamper perimeter zone restore '),
    array(139 , 0, 1,'Verified alarm '),
    array(143 , 0,11,'Alarm - communication bus trouble '),
    array(143 , 1,11,'Communication bus ok '),
    array(144 , 0, 1,'Alarm (zone tamper) '),
    array(144 , 1, 1,'Zone tamper restore '),
    array(145 , 0, 1,'Alarm (module tamper) '),
    array(145 , 1, 1,'Module tamper restore '),
    array(150 , 0, 1,'Alarm (24h no burglary zone) '),
    array(150 , 1, 1,'24h no burglary zone restore '),
    array(151 , 0, 1,'Alarm (gas detector) '),
    array(151 , 1, 1,'Gas detection zone restore '),
    array(152 , 0, 1,'Alarm (refrigeration) '),
    array(152 , 1, 1,'Refrigeration zone restore '),
    array(153 , 0, 1,'Alarm (heat loss) '),
    array(153 , 1, 1,'Heat loss zone restore '),
    array(154 , 0, 1,'Alarm (water leak) '),
    array(154 , 1, 1,'Water leak zone restore '),
    array(155 , 0, 1,'Alarm (protection loop break) '),
    array(155 , 1, 1,'Protection loop break zone restore '),
    array(156 , 0, 1,'Alarm (day/night zone tamper) '),
    array(156 , 1, 1,'Day/night zone tamper restore '),
    array(157 , 0, 1,'Alarm (low gas level) '),
    array(157 , 1, 1,'Low gas level zone restore '),
    array(158 , 0, 1,'Alarm (high temperature) '),
    array(158 , 1, 1,'High temperature zone restore '),
    array(159 , 0, 1,'Alarm (low temperature) '),
    array(159 , 1, 1,'Low temperature zone restore '),
    array(161 , 0, 1,'Alarm (no air flow) '),
    array(161 , 1, 1,'No air flow zone restore '),
    array(162 , 0, 1,'Alarm (carbon monoxide detected) '),
    array(162 , 1, 1,'Restore of carbon monoxide (CO) detection '),
    array(163 , 0, 1,'Alarm (tank level) '),
    array(163 , 1, 1,'Restore of tank level '),
    array(200 , 0, 1,'Alarm (fire protection loop) '),
    array(200 , 1, 1,'Fire protection loop zone restore '),
    array(201 , 0, 1,'Alarm (low water pressure) '),
    array(201 , 1, 1,'Low water pressure zone restore '),
    array(202 , 0, 1,'Alarm (low CO2 pressure) '),
    array(202 , 1, 1,'Low CO2 pressure zone restore'),
    array(203 , 0, 1,'Alarm (valve sensor)'),
    array(203 , 1, 1,'Valve sensor zone restore'),
    array(204 , 0, 1,'Alarm (low water level)'),
    array(204 , 1, 1,'Low water level zone restore'),
    array(205 , 0, 1,'Alarm (pump activated)'),
    array(205 , 1, 1,'Pump stopped'),
    array(206 , 0, 1,'Alarm (pump trouble)'),
    array(206 , 1, 1,'Pump ok'),
    array(220 , 0, 1,'Keybox open'),
    array(220 , 1, 1,'Keybox restore'),
    array(300 , 0, 4,'System module trouble'),
    array(300 , 1, 4,'System module ok'),
    array(301 , 0, 4,'AC supply trouble'),
    array(301 , 1, 4,'AC supply ok'),
    array(302 , 0, 4,'Low battery voltage'),
    array(302 , 1, 4,'Battery ok'),
    array(303 , 0, 0,'RAM memory error'),
    array(305 , 0, 4,'Main panel restart'),
    array(306 , 0, 0,'Main panel settings reset'),
    array(306 , 1, 0,'System settings restored from FLASH memory'),
    array(312 , 0, 1,'Supply output overload'),
    array(312 , 1, 1,'Supply output overload restore'),
    array(330 , 0, 8,'Proximity card reader trouble'),
    array(330 , 1, 8,'Proximity card reader ok'),
    array(333 , 0,11,'Communication bus trouble'),
    array(333 , 1,11,'Communication bus ok'),
    array(339 , 0, 4,'Module restart'),
    array(344 , 0, 1,'Receiver jam detected'),
    array(344 , 1, 1,'Receiver jam ended'),
    array(350 , 0, 0,'Transmission to monitoring station trouble'),
    array(350 , 1, 0,'Transmission to monitoring station ok'),
    array(351 , 0, 0,'Telephone line troubles'),
    array(351 , 1, 0,'Telephone line ok'),
    array(370 , 0, 1,'Alarm (auxiliary zone perimeter tamper)'),
    array(370 , 1, 1,'Auxiliary zone perimeter tamper restore'),
    array(373 , 0, 1,'Alarm (fire sensor tamper)'),
    array(373 , 1, 1,'Fire sensor tamper restore'),
    array(380 , 0, 1,'Zone trouble (masking)'),
    array(380 , 1, 1,'Zone ok (masking)'),
    array(381 , 0,32,'Radio connection troubles'),
    array(381 , 1,32,'Radio connection ok'),
    array(383 , 0, 1,'Alarm (zone tamper)'),
    array(383 , 1, 1,'Zone tamper restore'),
    array(384 , 0,32,'Low voltage on radio zone battery'),
    array(384 , 1,32,'Voltage on radio zone battery ok'),
    array(388 , 0, 1,'Zone trouble (masking)'),
    array(388 , 1, 1,'Zone ok (masking)'),
    array(400 , 0, 2,'Disarm'),
    array(400 , 1, 2,'Arm'),
    array(401 , 0, 2,'Disarm by user'),
    array(401 , 1, 2,'Arm by user'),
    array(402 , 0, 2,'Group disarm'),
    array(402 , 1, 2,'Group arm'),
    array(403 , 0,15,'Auto-disarm'),
    array(403 , 1,15,'Auto-arm'),
    array(404 , 0, 2,'Late disarm by user'),
    array(404 , 1, 2,'Late arm by user'),
    array(405 , 0, 2,'Deferred disarm by user'),
    array(405 , 1, 2,'Deferred arm by user'),
    array(406 , 0, 2,'Alarm cleared'),
    array(407 , 0, 2,'Remote disarm'),
    array(407 , 1, 2,'Remote arm'),
    array(408 , 1, 1,'Quick arm'),
    array(409 , 0, 1,'Disarm by zone'),
    array(409 , 1, 1,'Arm by zone'),
    array(411 , 0, 0,'Callback made'),
    array(412 , 0, 0,'Downloading successfully finished'),
    array(413 , 0, 0,'Unsuccessful remote downloading attempt'),
    array(421 , 0, 3,'Access denied'),
    array(421 , 1, 3,'Access denied'),
    array(422 , 0, 3,'User access'),
    array(422 , 1, 3,'User access'),
    array(423 , 0, 1,'Alarm - armed partition door opened'),
    array(441 , 1, 2,'Arm (STAY mode)'),
    array(442 , 1, 1,'Arm by zone (STAY mode)'),
    array(454 , 0, 2,'Arming failed'),
    array(458 , 0, 2,'Delay activation time started'),
    array(461 , 0, 1,'Alarm (3 wrong access codes)'),
    array(462 , 0, 3,'Guard round'),
    array(462 , 1, 3,'Guard round'),
    array(570 , 0, 1,'Zone bypass'),
    array(570 , 1, 1,'Zone unbypass'),
    array(571 , 0, 1,'Fire zone bypass'),
    array(571 , 1, 1,'Fire zone unbypass'),
    array(572 , 0, 1,'24h zone bypass'),
    array(572 , 1, 1,'24h zone unbypass'),
    array(573 , 0, 1,'Burglary zone bypass'),
    array(573 , 1, 1,'Burglary zone unbypass'),
    array(574 , 0, 1,'Group zone bypass'),
    array(574 , 1, 1,'Group zone unbypass'),
    array(575 , 0, 1,'Zone auto-bypassed (violations)'),
    array(575 , 1, 1,'Zone auto-unbypassed (violations)'),
    array(601 , 0, 6,'Manual transmission test'),
    array(602 , 0, 0,'Transmission test'),
    array(604 , 0, 2,'Fire/technical zones test'),
    array(604 , 1, 5,'End of fire/technical zones test'),
    array(607 , 0, 2,'Burglary zones test'),
    array(607 , 1, 5,'End of burglary zones test'),
    array(611 , 0, 1,'Zone test ok'),
    array(612 , 0, 1,'Zone not tested'),
    array(613 , 0, 1,'Burglary zone test ok'),
    array(614 , 0, 1,'Fire zone test ok'),
    array(615 , 0, 1,'Panic zone test ok'),
    array(621 , 0, 0,'Reset of event log'),
    array(622 , 0, 0,'Event log 50% full'),
    array(623 , 0, 0,'Event log 90% full'),
    array(625 , 0, 6,'Setting system real-time clock'),
    array(625 , 1, 0,'System real-time clock trouble'),
    array(627 , 0, 4,'Service mode started'),
    array(628 , 0, 4,'Service mode finished'),
    array(800 , 0, 6,'Key long pressed'), // TEST FUNCTION
    array(801 , 0, 4,'Settings sent - chime 1...64 ON'), // TEST FUNCTION
    array(802 , 0, 4,'Settings sent - chime 1...64 OFF'), // TEST FUNCTION
    array(803 , 0, 4,'Settings sent - chime 65..128 ON'), // TEST FUNCTION
    array(804 , 0, 4,'Settings sent - chime 65..128 OFF'), // TEST FUNCTION
    array(805 , 0, 4,'Settings sent - chime bypassed'), // TEST FUNCTION
    array(982 , 0, 6,'Change of user telephone code'),
    array(983 , 0, 6,'User telephone code broken'),
    array(984 , 0, 1,'Alarm - ABAX device tamper (no connection)'),
    array(984 , 1, 1,'ABAX device tamper restore (connection ok)'),
    array(985 , 0,15,'Exit time started'),
    array(986 , 0, 1,'Warning alarm'),
    array(987 , 0, 2,'Warning alarm cleared'),
    array(988 , 0, 1,'Arming aborted'),
    array(989 , 0, 7,'User logged in (INT-VG)'),
    array(989 , 1, 7,'User logged out (INT-VG)'),
    array(990 , 0, 4,'No connection with KNX system'),
    array(990 , 1, 4,'Connection with KNX system ok'),
    array(991 , 0, 1,'Zone auto-bypassed (tamper violations)'),
    array(991 , 1, 1,'Zone auto-unbypassed (tamper violations)'),
    array(992 , 0, 6,'Confirmed troubles'),
    array(993 , 0, 6,'Confirmed use of RX key fob with low battery'),
    array(994 , 0, 6,'Confirmed use of ABAX key fob with low battery'),
    array(995 , 0, 3,'Remote RX key fob with low battery used'),
    array(995 , 1, 3,'Remote RX key fob with low battery used'),
    array(996 , 0, 3,'Remote ABAX key fob with low battery used'),
    array(996 , 1, 3,'Remote ABAX key fob with low battery used'),
    array(997 , 0, 4,'Long transmitter busy state'),
    array(997 , 1, 4,'Restore of long transmitter busy state'),
    array(998 , 0, 0,'Transmission test (station 1)'),
    array(999 , 0, 0,'Transmission test (station 2)'),
    array(1000 , 0, 1,'Trouble (zone)'),
    array(1000 , 1, 1,'Trouble restore (zone)'),
    array(1001 , 0, 2,'Forced arming'),
    array(1002 , 0, 4,'No network (PING test)'),
    array(1002 , 1, 4,'Network ok (PING test)'),
    array(1003 , 0, 2,'Arming aborted'),
    array(1004 , 0, 0,'Downloading started from ETHM/GSM module'),
    array(1005 , 0, 6,'ETHM-1-downloading started'),
    array(1006 , 0, 4,'Current battery test - absent/low voltage'),
    array(1006 , 1, 4,'Current battery test - ok'),
    array(1007 , 0, 1,'Exit time started'),
    array(1008 , 0, 2,'Exit time started'),
    array(1009 , 0,14,'SMS control - begin'),
    array(1009 , 1,14,'SMS control - end'),
    array(1010 , 0,14,'SMS with no control received'),
    array(1011 , 0,14,'SMS from unauthorized telephone received'),
    array(1012 , 0, 6,'CSD-downloading started'),
    array(1013 , 0, 6,'GPRS-downloading started'),
    array(1014 , 0, 4,'No signal on DSR input'),
    array(1014 , 1, 4,'Signal on DSR input ok'),
    array(1015 , 0, 4,'Time server error'),
    array(1015 , 1, 4,'Time server ok'),
    array(1016 , 0, 6,'Time synchronization started'),
    array(1017 , 0, 9,'SMS messaging ok'),
    array(1018 , 0, 9,'SMS messaging failed'),
    array(1019 , 0, 3,'Remote key fob used'),
    array(1019 , 1, 3,'Remote key fob used'),
    array(1020 , 0, 1,'LCD/PTSA/ETHM-1 initiation error'),
    array(1021 , 0, 1,'LCD/PTSA/ETHM-1 initiation ok'),
    array(1022 , 0, 0,'Downloading request from ETHM-1 module')
    );
}
