# phpintegra
A PHP class for communication with the Satel ETHM-1 module for the Integra control panels.

Special thanks to the [IntegraPy] project which was my starting point.

[IntegraPy]: https://github.com/mkorz/IntegraPy

## FAQ

1. Which commands are supported?

| cmd | argument           | function                      |
|-----|--------------------|-------------------------------|
| 1A  |          -         | Clock and basic system status |
| 7E  |          -         | Integra type and version      |
| 8C  | last event: FFFFFF | Event information             |
| 00  |          -         | List of violated zones        |