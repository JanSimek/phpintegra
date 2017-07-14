<?php

require_once "src/ETHM.php";

// Ip address of your ETHM-1 module can be changed inside the integra panel:
// Service mode -> Structure -> Hardware -> LCD Keypads -> Settings -> [select the ETHM module from the list of devices]

$ethm = new Satel\ETHM("192.168.1.112", 7094, "1234");

$ethm->setLogging(true); // enable/disable log output
$ethm->setDebug(true); // enable/disable debug output

//$ethm->getCurrentUserInfo();

// Clear states
// FIXME: accepts any king of password and the response is always OK? WTF?
$ethm->send("8B1234FFFF");

// Set time
// FIXME: doesn't work. No response from Integra. Possibly their fault
$ethm->send("8E1234FFFF20170714105039");
//$ethm->syncTime();

$mask = "|%10.10s | %-30.30s |\n"; // nice printf formatting

printf("Integra type and version\n");
foreach ($ethm->send("7E") as $key => $value) {
    printf($mask, $key, $value);
}

printf("\nClock and basic system status\n");
foreach ($ethm->send("1A") as $key => $value) {
    printf($mask, $key, $value);
}

printf("\nLast 10 events\n");

$id = "FFFFFF"; // id of the last event is always FFFFFF

for ($i = 0; $i < 10; $i++) {
    $event = $ethm->send("8C" . $id);
    if (!$event["notempty"]) {
        break;
    }
    echo $event["date"] . " " . $event["time"] . ": " . $event["evtext"] . "\n";
    $id = $event["evindex"];
}

$ethm->send("7F");

printf("\nViolated zones\n");

$violatedZones = $ethm->send("00");
if (!empty(array_filter($violatedZones))) {
    foreach ($violatedZones as $number => $name) {
        printf("[%3s] %s\n", $number, $name);
    }
} else {
    echo "No violated zones\n";
}

$tamperedZones = $ethm->send("01");
!empty(array_filter($tamperedZones)) or printf("-> No tampered zones");

foreach ($tamperedZones as $number => $name) {
    printf("[%3s] %s\n", $number, $name);
};

echo "\nOpened doors: " . $ethm->send("18") . "\n";
echo "Opened doors (long): " . $ethm->send("19") . "\n";

for ($i = 0; $i <= 8; $i++) {
    echo "Sending command 0x0" . $i . "\n";
    $zones = $ethm->send("0" . $i);

    if (empty($zones))
    {
        echo "No zones in 0" . $i . "\n";
        continue;
    }

    foreach ($zones as $number => $name) {
        printf("[%3s] %s\n", $number, $name);
    };
}


// TODO: what about other types such as users, outputs or partitions?
/*
echo "Listing all zones\n";
for ($i = 1; $i < 96; $i++) {
    if ($ethm->send("EE01" . sprintf("%02s", dechex($i))) != false) {
        echo sprintf("[%02s] ", $i) . $ethm->send("EE01" . sprintf("%02s", dechex($i))) . "\n";
    }
}
*/
