<?php

require_once "src/Integra.php";

// Ip address of your ETHM-1 module can be changed inside the integra panel:
// Service mode -> Structure -> Hardware -> LCD Keypads -> Settings -> [select the ETHM module from the list of devices]

$satel = new Satel\Integra("192.168.1.112");

$satel->setLogging(false); // disable log output
//$satel->setDebug(true); // enable debug output

$mask = "|%10.10s | %-30.30s |\n"; // nice printf formatting

printf("Integra type and version\n");
foreach ($satel->sendCommand("7E") as $key => $value) {
    printf($mask, $key, $value);
}

printf("\nClock and basic system status\n");
foreach ($satel->sendCommand("1A") as $key => $value) {
    printf($mask, $key, $value);
}

printf("\nLast 10 events\n");

$id = "FFFFFF"; // id of the last event is always FFFFFF

for ($i = 0; $i < 10; $i++) {
    $event = $satel->sendCommand("8C" . $id);
    if (!$event["notempty"]) {
        break;
    }
    echo $event["date"] . " " . $event["time"] . ": " . $event["evtext"] . "\n";
    $id = $event["evindex"];
}

printf("\nViolated zones\n");

$violatedZones = $satel->sendCommand("00");
if (!empty(array_filter($violatedZones))) {
    foreach ($violatedZones as $number => $name) {
        printf("[%3s] %s\n", $number, $name);
    }
} else {
    echo "No violated zones\n";
}

/*
$tamperedZones = $satel->sendCommand("01");
!empty(array_filter($tamperedZones)) or $satel->log("info", "-> No tampered zones");

foreach ($tamperedZones as $number => $name) {
    $satel->log("info", sprintf("[%3s] %s", $number, $name));
};

echo "Opened doors: " . $satel->sendCommand("18") . "\n";
echo "Opened doors (long): " . $satel->sendCommand("19") . "\n";

for ($i = 0; $i <= 8; $i++) {
    echo "Sending command 0x0" . $i . "\n";
    $zones = $satel->sendCommand("0" . $i);

    if (empty($zones)
    {
        echo "No zones in 0" . $i . "\n";
        continue;
    }

    foreach ($zones as $number => $name) {
        $satel->log("info", sprintf("[%3s] %s", $number, $name));
    };
}

echo "Listing all zones\n";
for ($i = 1; $i < 96; $i++) {
    if ($satel->sendCommand("EE01" . sprintf("%02s", dechex($i))) != false) {
        echo sprintf("[%02s] ", $i) . $satel->sendCommand("EE01" . sprintf("%02s", dechex($i))) . "\n";
    }
}*/
