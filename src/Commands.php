<?php
/**
 * Response handlers for ETHM packets
 * @package Satel\Commands
 */
namespace Satel\Command;

require_once "Command.php";

use Satel\Command as Command;

/**
 * Used by the ETHM class for sending outgoing commands
 */
class OutgoingCommand extends Command {
	/**
	 * Unused
	 */
	public function handle($response) {}
}

/**
 * Read device name
 *
 * **Response format**
 * <pre>
 * 0xFE 0xFE 0xEE 0xXX 0xXX 0xXX N A M E .. .. .. 0xFE 0x0D
 *                |    |    |    \_ 6. start of an ASCII string encoded in decimal numbers
 *                |    |    \_ 5. device type/function
 *                |    \_ 4. device number
 *                 \_ 3. device type
 * </pre>
 */
class xEE extends Command {
	/**
	 * @param string $response 0xEE (total 20 bytes or 21 bytes if it is device type to read number 2)
	 */
	public function handle($response) {
        $response = $this->trimWrapper($response, 6); // see above
        $response = $this->toBytearray($response);
        $response = array_map("chr", array_map("hexdec", $response));

        return implode($response); // TODO: strip trailing whitespace?
	}
}

/**
 * Zone report
 *
 * Handles the following codes:
 * - 0x00: Zones violation
 * - 0x01: Zones tamper
 * - 0x02: Zones alarm
 * - 0x03: Zones tamper alarm
 * - 0x04: Zones alarm memory
 * - 0x05: Zones tamper alarm memory
 * - 0x06: Zones bypass
 * - 0x07: Zones 'no violation trouble'
 * - 0x08: Zones 'long violation trouble'
 */
class x00 extends Command {
	/**
	 * @param string $response 0x00 + 16/32 bytes (*) 
	 */
	public function handle($response) {
            return $this->checkZones($response);
	}

    /**
     * No clue what's happening here. Copied from Marcin's IntegraPy project
     * @link https://github.com/mkorz/IntegraPy/blob/master/integrademo.py#L185
     * @param string $raw ETHM response
     */
    private function checkZones($raw)
    {
        $this->ethm->log("info", "Checking zones.");

        $violated = array();

        // FIXME: this checks for 16*8=128 zones; number of zones varies with different Integra models
        for ($i = 0; $i < 16; $i++) {
            for ($b = 0; $b < 8; $b++) {
                if (pow(2, $b) & hexdec(bin2hex($raw[$i+3]))) { // 0xFE 0xFE 0x00 ..
                    $number = 8 * $i + $b + 1;
                    // TODO: self::PARTITION, self::OUTPUT, etc.
                    $violated[$number] = $this->ethm->send("EE" . self::ZONEX . sprintf("%02s", dechex($number)));
                }
            }
        }

        return $violated;
    }
}

/**
 * Opened doors
 *
 * Handles the following codes:
 * * 0x18: Doors opened
 * * 0x19: Doors opened long
 */
class x18 extends Command {
	/**
	 * @param string $response 0x18 or 0x19 + 8 bytes
	 */
	public function handle($response) {
		return bin2hex($response); // TODO
	}
}

/**
 * Clock and basic system status
 *
 * **Response format**
 * <pre>
 * 7 bytes - time: YYYY-MM-DD hh:mm:ss = 0xYY , 0xYY , 0xMM, 0xDD, 0xhh, 0xmm, 0xss
 * 1 byte  - .210 - day of the week (0 = Monday, 1 = Tuesday, ..., 6 = Sunday)
 *           .7 - 1 = service mode
 *           .6 - 1 = troubles in the system (= flashing TROUBLE LED in keypad)
 * 1 byte  - .7 - 1 = ACU-100 are present in the system
 *           .6 - 1 = INT-RX are present in the system
 *           .5 - 1 = troubles memory is set in INTEGRA panel
 *           .4 - 1 = Grade2/Grade3 option is set in INTEGRA panel
 *        .3210 - INTEGRA type:
 *                0 = INTEGRA 24
 *                1 = INTEGRA 32
 *                2 = INTEGRA 64 / INTEGRA 64 PLUS
 *                3 = INTEGRA 128 / INTEGRA 128 PLUS
 *                4 = INTEGRA 128-WRL
 *                8 = INTEGRA 256 PLUS
 *                (to read detailed type use 0x7E command)
 * </pre>
 */
class x1A extends Command {
	/**
	 * @param string $response 0x1A + 9 bytes
	 */
	public function handle($response) {
			$raw = $response;
            $response = $this->trimWrapper($response);

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
            $this->ethm->log("info", "Integra type : " . $type);

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
            $this->ethm->log("info", "Integra time : " . $datetime);

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

            $this->ethm->log("info", "Service mode : " . ($service ? "yes" : "no"));
            $this->ethm->log("info", "Troubles     : " . ($troubles ? "yes" : "no"));
            $this->ethm->log("info", "ACU-100      : " . ($acu100 ? "yes" : "no"));
            $this->ethm->log("info", "INT-Rx       : " . ($intrx ? "yes" : "no"));
            $this->ethm->log("info", "Troubles mem.: " . ($troublemem ? "yes" : "no"));
            $this->ethm->log("info", "Grade32Set   : " . ($grade32set ? "yes" : "no"));

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
	}
}

/**
 * INT-RS/ETHM-1 module version
 *
 * **Response format**
 * <pre>
 *       11 bytes: version, e.g. '12320120527'
 *       1  byte : .0 - 1 = module can serve 32 data bytes for zones/outputs
 * </pre>
 *
 * NOTE: Modules ealier than 2013-11-08 do not know this command, so they will not reply
 *       Since mine is one of those older ones, I couldn't test this command
 */
class x7C extends Command {
	/**
	 * @param string $response 0x7C + 12 bytes
	 */
	public function handle($response) {
            $response = $this->trimWrapper($response);
            $response = $this->toBytearray($response);

            $version = array_map("chr", array_map("hexdec", array_slice($response, 0, 10)));
            $version = vsprintf("%s.%s%s %s%s%s%s-%s%s-%s%s", $version);
            $this->ethm->log("info", "ETHM-1 Version: " . $version);

            $canserve32 = (($response[11] >> 0) & 1);
            $this->ethm->log("info", "Can serve  32b: " . ($canserve32 ? "yes" : "no"));

            return array("version" => $version, "canserve32" => $canserve32);
	}
}

/**
 * INTEGRA version
 *
 * **Response format**
 * <pre>
 * 1  byte : INTEGRA type
 * 11 bytes: version, e.g. '12320120527'
 * 1  byte : language (1 = English, 7 = Czech, others unknown)
 * 1  byte : stored in flash (255 = true, otherwise false)
 * </pre>
 *
 * NOTE: Modules ealier than 2013-11-08 do not know this command, so they will not reply
 *       Since mine is one of those older ones, I couldn't test this command
 */
class x7E extends Command {
	/**
	 * @param string $response 0x7E + 14 bytes
	 */
	public function handle($response) {
            $response = $this->trimWrapper($response);
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
            
            $this->ethm->log("info", "Integra type : " . $type . "(" . $zones . " zones)");

            $version = array_map("chr", array_map("hexdec", array_slice($response, 1, 11)));
            $version = vsprintf("%s.%s%s %s%s%s%s-%s%s-%s%s", $version);
            $this->ethm->log("info", "Version      : " . $version);
            
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
	}
}

/**
 * Returned command result
 */
class xEF extends Command {
	/**
	 * @param string $response 0xEF + 1 byte (result code)
	 */
	public function handle($response) {
		switch (bin2hex($response[3])) {
            case 0x00:
                $this->ethm->log("info", "ok");
                return true;
            case 0x01:
                $this->ethm->log("error", "requested user code not found");
                return false;
            case 0x02:
                $this->ethm->log("error", "no access");
                return false;
            case 0x03:
                $this->ethm->log("error", "selected user does not exist");
                return false;
            case 0x04:
                $this->ethm->log("error", "selected user already exists");
                return false;
            case 0x05:
                $this->ethm->log("error", "wrong code or code already exists");
                return false;
            case 0x06:
                $this->ethm->log("error", "telephone code already exists");
                return false;
            case 0x07:
                $this->ethm->log("error", "changed code is the same");
                return false;
            case 0x08:
                $this->ethm->log("error", "other error");
                return false;
            case 0x11:
                $this->ethm->log("error", "can not arm, but can use force arm");
                return false;
            case 0x12:
                $this->ethm->log("error", "can not arm");
                return false;
            case 0x8: // FIXME: is this right? Docs say "0x8?"
                $this->ethm->log("error", "other errors");
                return false;
            case 0xFF:
                $this->ethm->log("info", "command accepted (i.e. data length and crc ok), will be processed");
                return false;
            default:
                $this->ethm->log("error", "unknown result code " . bin2hex($raw[3]));
                return false;
        }
	}
}

/**
 * Read event
 * 
 * **Response format**
 *
 * | Bit       |  .7   |  .6   |  .5   |  .4   |  .3   |  .2   |  .1   |  .0   |
 * | :---------|:-----:|:-----:|:-----:|:-----:|:-----:|:-----:|:-----:|:-----:|
 * | 1st byte: |   Y   |   Y   |   Z   |   E   |   S2  |   S2  |   S1  |   S1  |
 * | 2nd byte: |   K   |   K   |   K   |   D   |   D   |   D   |   D   |   D   |
 * | 3rd byte: |   M   |   M   |   M   |   M   |   T   |   T   |   T   |   T   |
 * | 4th byte: |   t   |   t   |   t   |   t   |   t   |   t   |   t   |   t   |
 * | 5th byte: |   P   |   P   |   P   |   P   |   P   |   R   |   C   |   C   |
 * | 6th byte: |   c   |   c   |   c   |   c   |   c   |   c   |   c   |   c   |
 * | 7th byte: |   n   |   n   |   n   |   n   |   n   |   n   |   n   |   n   |
 * | 8th byte: |   S   |   S   |   S   |   u   |   u   |   u   |   u   |   u   |
 */
class x8C extends Command {
	/**
	 * @param string $response 0x8C (15 bytes total)
	 */
	public function handle($response) {
		$raw = $response;
        $response = $this->trimWrapper($response);
        $response = $this->toBytearray($response);

        $byte1 = hexdec(bin2hex($raw[3]));

        // Show all bits in a byte. For debugging.
        //for ($bit = 7; $bit >= 0; $bit--) {
        //    echo "Bit " . $bit . ": " . (($byte1 >> $bit) & 1) . "\n";
        //}

        $year = $this->sumBits($byte1, 7, 6);
        $yearmod = date("Y") % 4;
        $this->ethm->log("debug", "Year: " . $year . " should equal => (" . date("Y") . " % 4) = " . $yearmod);
        
        $notempty = (($byte1 >> 5) & 1);
        $this->ethm->log("debug", "Not empty    : " . ($notempty ? "yes" : "no"));
        $epresent = (($byte1 >> 4) & 1);
        $this->ethm->log("debug", "Event present: " . ($epresent ? "yes" : "no"));

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
        $this->ethm->log("debug", "S2           : " . $s2status);
        $this->ethm->log("debug", "S1           : " . $s1status);

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
        $this->ethm->log("info", "Event (KKK)  : " . $ekkk);

        //$day = bindec((($byte2 >> 4) & 1) . (($byte2 >> 3) & 1) . (($byte2 >> 2) & 1) . (($byte2 >> 1) & 1) . (($byte2 >> 0) & 1));
        $day = sprintf("%02d", $this->sumBits($byte2, 4, 0));

        $byte3 = hexdec(bin2hex($raw[5]));

        $month = sprintf("%02d", $this->sumBits($byte3, 7, 4));

        $this->ethm->log("info", "Date:        : " . $day . "/" . $month);

        $time1 = (($byte3 >> 3) & 1) . (($byte3 >> 2) & 1) . (($byte3 >> 1) & 1) . (($byte3 >> 0) & 1);

        $byte4 = hexdec(bin2hex($raw[6]));

        $time2 = (($byte4 >> 7) & 1) . (($byte4 >> 6) & 1) . (($byte4 >> 5) & 1) . (($byte4 >> 4) & 1) . (($byte4 >> 3) & 1) . (($byte4 >> 2) & 1) . (($byte4 >> 1) & 1) . (($byte3 >> 0) & 1);

        $time = bindec($time1 . $time2);
        $hours = sprintf("%02d", floor($time/60));
        $minutes = sprintf("%02d", $time - $hours * 60);
        $this->ethm->log("info", "Time         : " . $hours . ":" . $minutes);

        $byte5 = hexdec(bin2hex($raw[7]));

        $partition = $this->sumBits($byte5, 7, 3);
        $this->ethm->log("info", "Partition No.: " . $partition);

        $restore = (($byte5 >> 2) & 1) ;
        $this->ethm->log("info", "Restore      : " . ($restore ? "yes" : "no"));

        // event code CC in byte5
        $evcode1 = (($byte5 >> 1) & 1) . (($byte5 >> 0) & 1);

        // event code cccccccc in byte6
        // - use command 0x8F to convert to text (we keep statuses stored in an array)
        $byte6 = hexdec(bin2hex($raw[8]));
        $evcode2 = (($byte6 >> 7) & 1) . (($byte6 >> 6) & 1) . (($byte6 >> 5) & 1) . (($byte6 >> 4) & 1) . (($byte6 >> 3) & 1) . (($byte6 >> 2) & 1) . (($byte6 >> 1) & 1) . (($byte6 >> 0) & 1);

        // event code = CC + cccccccc
        $evcode = bindec($evcode1 . $evcode2);
        $this->ethm->log("info", "Event code   : " . $evcode);

        foreach ($this->eventList as $event) {
            if ($event[0] ==  $evcode && $event[1] == $restore) {
                $evcategory = $this->eventCategory[$event[2]]; // $event[2];
                $evtext = $event[3];
                $this->ethm->log("info", "Event text   : " . $event[3]) . " [" . $this->eventCategory[$event[2]] . "]";
            }
        }

        /*      
        byte7 - nnnnnnnn - source number (e.g. zone number, user number)
            - if users numbering:
                1..240   - user
                241..248 - master
                249      - INT-AV
                251      - SMS
                252      - timer
                253      - function zone
                254      - Quick arm
                255      - service
            - if zone|expander|keypad numbering:
                1..128   - zone 
                129..192 - expander address
                INTEGRA 24 and 32:
                    193..196 - real LCD keypads or INT-RS modules at address 0..3
                    197..200 - keypad in GuardX connected to LCD keypad at address 0..3, or www keypad in internet browser connected to ETHM-1 at address 0..3
                    201      - keypad in DloadX connected to INTEGRA via RS cable
                    202      - keypad in DloadX connected to INTEGRA via TEL link (modem)
                INTEGRA 64, 128 and 128-WRL:
                    193..200 - real LCD keypads or INT-RS modules at address 0..7
                    201..208 - keypad in GuardX connected to LCD keypad at address 0..7, or www keypad in internet browser connected to ETHM-1 at address 0..7
                    209      - keypad in DloadX connected to INTEGRA via RS cable
                    210      - keypad in DloadX connected to INTEGRA via TEL link (modem)
            - if output|expander numbering:
                1..128    - output 
                129..192  - supply output in expander at address 0..63

        Note: in INTEGRA 256 PLUS - if event record describes zone or output (1..128), so read the uuuuu field and: if uuuuu = 00000 - the zone or output number is 1..128,
        if uuuuu = 00001 - add 128 to the zone or output number - i.e. 1..128 becomes 129..256.
        */
        $byte7 = hexdec(bin2hex($raw[9]));
        $this->ethm->log("info", "Source number: " . $byte7);

        //      byte8 - SSSuuuuu            
        $byte8 = hexdec(bin2hex($raw[10]));

        // SSS - object number (0..7)
        // FIXME: sumBits()
        $objectn = (($byte8 >> 7) & 1) . (($byte8 >> 6) & 1) . (($byte8 >> 5) & 1);
        $this->ethm->log("info", "Object number: " . $objectn);

        // uuuuu - user control number
        // FIXME: sumBits()
        $usern = (($byte8 >> 4) & 1) . (($byte8 >> 3) & 1) . (($byte8 >> 2) & 1) . (($byte8 >> 1) & 1) . (($byte8 >> 0) & 1);
        $this->ethm->log("info", "User control#: " . $usern);


        $evindex = $response[8] . $response[9] . $response[10];
        $this->ethm->log("info", "Event index  : " . $evindex);

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
            "evcategory"=> $evcategory,
            "evindex"   => $evindex
            );
	}
}
?>