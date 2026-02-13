#8th February 2025
- Switching jeedom logic id from UUID to Internal ID to take into account devices linked to HUB that get same UUID as the HUB.
- Add compatibility with MS405 & MS100 (through HUB)
- Live gather of switching on/off, water leak alarm, ...
- Improve images (for example MSL320CP & MSL320HK & MSL320PHK have the same image) decrease of size for the application
- Performance : better use of loops for async methods + no connection at each action, the connection stay open

#22nd January 2025
- MerossIOT 0.4.10.4 (for those using Meross HUB)
- Reduce info logs

# 17th March 2025
- Bug fixes
- MerossIOT 0.4.9.0
- Drops python 3.7 support
- Upgrades paho-mqtt dependency to v2

# 16th january 2025
- Deprecated function update : jeedom.eqLogic.builSelectCmd replaced by jeedom.eqLogic.buildSelectCmd

# 15th january 2025
- Change python install directory to avoid reinstalling dependencies each time jeedom restarts

# 14th november 2024
- Add compatibility with MTS960

# 11th july 2024
- Update compatibility with Meross API. Including updgrade to avoid "mfaLockExpire" error

# 0.9
- Add thermostat

# 0.8
- Add roller manager
- Add oil diffuser

# 0.7
- Add light manager

# 0.6
- Add garage opener manager

# 0.5
- Gather swith names for power strip
- Fix issue to update all switches when using master switch

# 0.4
- Error message update in case of connection issue
- Increase reactivity when launching daemon when it fails

# 0.3
- Error fix after API Meross upgrade
- Update [merossIOT 0.4.5.4](https://github.com/albertogeniola/MerossIot) version

# 0.2
- Get errors when testing connection in order to simplify message display in the log

# 0.1

- First version
- Fork from [plugin of Jeremie-C](https://github.com/Jeremie-C/plugin-MerossIOT)
- Update version [merossIOT 0.4.4.7](https://github.com/albertogeniola/MerossIot)


> Detailed changelog :
> <https://github.com/impulsio/MerosSync/commits/main>
