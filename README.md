# phpintegra
A PHP class for communication with the Satel ETHM-1 module for the Integra control panels.

Special thanks to the [IntegraPy] project which was my starting point.

[IntegraPy]: https://github.com/mkorz/IntegraPy

## Note

Currently, phpIntegra checks only 128 zones. If your model supports more than that, e.g. INTEGRA 256 PLUS, then you have to manually adapt the for loop in the ```checkZones()``` function.

## FAQ

1. Which commands are supported?

    | cmd | argument           | function                      |
    |-----|--------------------|-------------------------------|
    | 1A  |          -         | Clock and basic system status |
    | 7E  |          -         | Integra type and version      |
    | 8C  | last event: FFFFFF | Event information             |
    | 00  |          -         | List of violated zones        |

2. How to use this class via Composer

    Install composer and create ```composer.json``` file in your project directory. Paste in the contents below and finally execute ```composer install```

    ```json
    {
        "require":
        {
            "satel": "dev-master"
        },
        "repositories":
        [
            {
                "type": "vcs",
                "url": "https://github.com/JanSimek/phpintegra.git"
            }
        ]
    }
    ```

    Then create ```test.php```:

    ```php
    require __DIR__ . '/vendor/autoload.php';

    $satel = new Satel\Integra("192.168.1.112"); // ip address of your ETHM-1 module
    ```
